<?php

namespace App\Jobs;

use App\Models\Country;
use App\Models\LotteryResult;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCountryNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    protected Country $country;
    protected array $resultIds;
    protected ?string $title;
    protected ?string $body;

    public function __construct(Country $country, array $resultIds = [], ?string $title = null, ?string $body = null)
    {
        $this->country = $country;
        $this->resultIds = $resultIds;
        $this->title = $title;
        $this->body = $body;
    }

    public function handle(FirebaseService $firebase): void
    {
        $start = microtime(true);
        try {
            $availableAt = $this->job?->availableAt();
        } catch (\Throwable $e) {
            $availableAt = null;
        }

        $topic = $this->topicForCountry($this->country->slug);

        if (!$topic) {
            Log::warning("Sin tópico FCM configurado para país: {$this->country->slug}");
            return;
        }

        $data = [
            'type' => 'new_results',
            'country' => $this->country->slug,
            'timestamp' => (string) now()->timestamp,
        ];

        $result = LotteryResult::with(['country', 'game'])->find($this->resultIds[0] ?? null);
        if ($result) {
            $data['country_name'] = $result->country->name ?? $this->country->name;
            $data['country_flag'] = $result->country->flag ?? '';
            $data['game'] = $result->game->name ?? '';
            $data['numbers'] = implode(',', $result->winning_numbers ?? []);
            $data['draw_time'] = $result->draw_time ?? '';
            $data['draw_date'] = $result->draw_date instanceof \Illuminate\Support\Carbon
                ? $result->draw_date->format('Y-m-d')
                : (string) ($result->draw_date ?? '');
        }

        if ($this->title !== null) {
            $data['title'] = $this->title;
            $data['body'] = $this->body ?? 'Resultado de prueba LOTO';
        }

        $status = $firebase->sendToTopic($topic, $data);
        $fcmMs = round((microtime(true) - $start) * 1000, 1);

        Log::warning('FCM-DIAG SendCountryNotification ejecutado', [
            'country' => $this->country->slug,
            'topic' => $topic,
            'status' => $status,
            'result_ids' => $this->resultIds,
            't_start' => now()->format('Y-m-d H:i:s.v'),
            'queued_delay_s' => $availableAt ? round(max(0, now()->timestamp - (int) $availableAt), 3) : null,
            'attempts' => $this->attempts(),
            'fcm_ms' => $fcmMs,
            'draw_time' => $data['draw_time'] ?? null,
            'game' => $data['game'] ?? null,
        ]);

        if ($status !== FirebaseService::STATUS_SENT) {
            throw new \RuntimeException("FCM send failed for {$topic}: {$status}");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendCountryNotification failed after {$this->tries} attempts", [
            'country' => $this->country->slug,
            'result_ids' => $this->resultIds,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function topicForCountry(string $slug): ?string
    {
        return [
            'nicaragua' => 'lottery_ni',
            'costa-rica' => 'lottery_cr',
            'guatemala' => 'lottery_gt',
            'honduras' => 'lottery_hn',
            'el-salvador' => 'lottery_sv',
            'republica-dominicana' => 'lottery_do',
            'belice' => 'lottery_bz',
            'nicaragua' => 'lottery_ni',
        ][$slug] ?? null;
    }
}
