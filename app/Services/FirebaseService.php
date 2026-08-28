<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    public const STATUS_SENT = 'sent';
    public const STATUS_UNREGISTERED = 'unregistered';
    public const STATUS_ERROR = 'error';
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    protected ?string $serverKey;
    protected ?string $projectId;
    protected ?array $serviceAccount;

    public function __construct()
    {
        $this->serverKey = config('firebase.server_key') ?? env('FCM_SERVER_KEY');
        $this->projectId = config('firebase.project') ?? env('FIREBASE_PROJECT_ID');
        $this->serviceAccount = $this->loadServiceAccount();

        if ($this->serviceAccount) {
            $token = $this->getAccessToken();
            if (!$token) {
                Log::error('FCM: no se pudo obtener token OAuth2 en el constructor');
            }
        } else {
            Log::warning('FCM: service account no cargada');
        }
    }

    public function sendDataMessage(string $token, array $data = []): string
    {
        if ($this->serviceAccount) {
            return $this->sendV1($token, $data);
        }

        return $this->sendLegacy($token, $data);
    }

    public function sendToTopic(string $topic, array $data = []): string
    {
        if ($this->serviceAccount) {
            return $this->sendTopicV1($topic, $data);
        }

        return $this->sendTopicLegacy($topic, $data);
    }

    public function activeTransport(): string
    {
        if ($this->serviceAccount) {
            return 'v1 (service account)';
        }

        if ($this->serverKey) {
            return 'legacy (server key)';
        }

        return 'ninguno (FCM no configurado)';
    }

    protected function loadServiceAccount(): ?array
    {
        $file = config('firebase.credentials.file');
        if (!$file || !is_file($file)) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($file), true);
            return is_array($json) && isset($json['client_email'], $json['private_key'], $json['project_id'])
                ? $json
                : null;
        } catch (\Throwable $e) {
            Log::warning('FCM service account no pudo cargarse: ' . $e->getMessage());
            return null;
        }
    }

    protected function sendV1(string $token, array $data): string
    {
        $project = $this->projectId ?: ($this->serviceAccount['project_id'] ?? null);
        if (!$project) {
            return self::STATUS_ERROR;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return self::STATUS_ERROR;
        }

        $payload = [
            'message' => [
                'token' => $token,
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", $payload);

            if ($response->successful()) {
                return self::STATUS_SENT;
            }

            if (str_contains($response->body(), 'UNREGISTERED')) {
                Log::warning("FCM v1 token UNREGISTERED: {$response->status()} {$response->body()}");
                return self::STATUS_UNREGISTERED;
            }

            Log::warning("FCM v1 send failed: {$response->status()} {$response->body()}");
            return self::STATUS_ERROR;
        } catch (\Throwable $e) {
            Log::error('FCM v1 send error: ' . $e->getMessage());
            return self::STATUS_ERROR;
        }
    }

    protected function sendTopicV1(string $topic, array $data): string
    {
        $project = $this->projectId ?: ($this->serviceAccount['project_id'] ?? null);
        if (!$project) {
            return self::STATUS_ERROR;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return self::STATUS_ERROR;
        }

        $title = $data['title'] ?? ($data['game'] ?? 'LOTO');
        $body = $data['body'] ?? ($data['numbers'] ?? '');

        $payload = [
            'message' => [
                'topic' => $topic,
                'data' => array_merge($data, [
                    'title' => $title,
                    'body' => $body,
                ]),
                'android' => [
                    'priority' => 'high',
                ],
            ],
        ];

        $fcmMs = null;
        try {
            $start = microtime(true);
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", $payload);
            $fcmMs = round((microtime(true) - $start) * 1000, 1);

            if ($response->successful()) {
                Log::warning('FCM-DIAG respuesta Firebase OK', [
                    'topic' => $topic,
                    'http_status' => $response->status(),
                    'fcm_ms' => $fcmMs,
                    't_send' => now()->format('Y-m-d H:i:s.v'),
                ]);
                return self::STATUS_SENT;
            }

            Log::warning("FCM v1 topic send failed: {$response->status()} {$response->body()} (fcm_ms=" . ($fcmMs ?? 'n/a') . ')');
            return self::STATUS_ERROR;
        } catch (\Throwable $e) {
            Log::error('FCM v1 topic send error: ' . $e->getMessage() . ' (fcm_ms=' . ($fcmMs ?? 'n/a') . ')');
            return self::STATUS_ERROR;
        }
    }

    protected function sendTopicLegacy(string $topic, array $data): string
    {
        if (!$this->serverKey) {
            Log::warning('FCM server key not configured');
            return self::STATUS_NOT_CONFIGURED;
        }

        $payload = [
            'to' => '/topics/' . $topic,
            'data' => array_merge($data, ['click_action' => 'OPEN_RESULTS']),
            'priority' => 'high',
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'key=' . $this->serverKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://fcm.googleapis.com/fcm/send', $payload);

            if (!$response->successful()) {
                Log::warning("FCM legacy topic send failed: {$response->status()} {$response->body()}");
                return self::STATUS_ERROR;
            }

            return self::STATUS_SENT;
        } catch (\Throwable $e) {
            Log::error('FCM legacy topic send error: ' . $e->getMessage());
            return self::STATUS_ERROR;
        }
    }

    protected function getAccessToken(): ?string
    {
        $cacheKey = 'fcm_access_token';
        $store = Cache::store('redis');
        $cached = $store->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $account = $this->serviceAccount;
        $now = time();

        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64Url(json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = $header . '.' . $claims;
        $signature = '';
        openssl_sign($signingInput, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $signingInput . '.' . self::base64Url($signature);

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (!$response->successful()) {
                Log::warning('FCM access token request failed: ' . $response->body());
                return null;
            }

            $token = $response->json('access_token');
            if ($token) {
                $store->put($cacheKey, $token, now()->addMinutes(55));
            }

            Log::info('FCM access token obtained successfully');
            return $token;
        } catch (\Throwable $e) {
            Log::error('FCM access token error: ' . $e->getMessage());
            return null;
        }
    }

    protected static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function sendLegacy(string $token, array $data): string
    {
        if (!$this->serverKey) {
            Log::warning('FCM server key not configured');
            return self::STATUS_NOT_CONFIGURED;
        }

        $payload = [
            'to' => $token,
            'data' => array_merge($data, ['click_action' => 'OPEN_RESULTS']),
            'priority' => 'high',
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'key=' . $this->serverKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://fcm.googleapis.com/fcm/send', $payload);

            if (!$response->successful()) {
                Log::warning("FCM legacy send failed: {$response->status()} {$response->body()}");
                return self::STATUS_ERROR;
            }

            $error = $response->json('results.0.error');
            if ($error === 'NotRegistered' || $error === 'InvalidRegistration') {
                return self::STATUS_UNREGISTERED;
            }

            return self::STATUS_SENT;
        } catch (\Throwable $e) {
            Log::error('FCM legacy send error: ' . $e->getMessage());
            return self::STATUS_ERROR;
        }
    }
}
