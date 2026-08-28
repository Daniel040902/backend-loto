<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NicaraguaScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://loto.com.ni';

    protected array $games = [
        'diaria' => ['name' => 'La Diaria'],
        'premiado' => ['name' => 'Premia 2'],
        'juga_tres' => ['name' => 'Jugá 3'],
        'juga_cuatro' => ['name' => 'Jugá 4'],
        'fechas_lotos' => ['name' => 'Fechas'],
        'terminacion2' => ['name' => 'Terminación 2'],
    ];

    protected array $drawTimeMap = [
        '11:00' => '11:00 AM',
        '12:00' => '12:00 PM',
        '15:00' => '3:00 PM',
        '18:00' => '6:00 PM',
        '21:00' => '9:00 PM',
    ];

    public function getCountrySlug(): string
    {
        return 'nicaragua';
    }

    public function getApiBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getCalendarBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function fetchResults(\DateTime $date): array
    {
        $results = $this->fetchCalendarApi($date);

        if (empty($results)) {
            $yesterday = (clone $date)->modify('-1 day');
            Log::info('LOTO NI sin resultados calendar para ' . $date->format('Y-m-d') . ', intentando ayer');
            $results = $this->fetchCalendarApi($yesterday);
        }

        return $results;
    }

    protected function fetchCalendarApi(\DateTime $date): array
    {
        $results = [];
        $dateStr = $date->format('Y-m-d');

        foreach ($this->games as $apiName => $config) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'application/json, text/plain, */*',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->get($this->baseUrl . "/api/resultados_calendario_{$apiName}.php", ['fecha' => $dateStr]);

                if (!$response->successful()) {
                    Log::warning("LOTO NI HTTP {$response->status()} for {$apiName}");
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    continue;
                }

                foreach ($data as $timeKey => $slot) {
                    if (empty($slot) || !isset($this->drawTimeMap[$timeKey])) {
                        continue;
                    }

                    $parsed = $this->parseDrawSlot($slot, $config['name'], $date);
                    if ($parsed) {
                        $results[] = array_merge($parsed, [
                            'game_name' => $config['name'],
                            'draw_time' => $this->drawTimeMap[$timeKey],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("LOTO NI failed for {$apiName}: " . $e->getMessage());
            }
        }

        return $results;
    }

    protected function parseDrawSlot($slot, string $gameName, \DateTime $date): ?array
    {
        $winningNumbers = [];
        $prizes = null;

        switch ($gameName) {
            case 'La Diaria':
                if (is_array($slot) && count($slot) >= 2) {
                    $winningNumbers[] = $this->normalizeNumber((string) $slot[0] . (string) $slot[1]);
                    $prizes = $this->buildDiariaPrizes($slot[2] ?? null, $slot[3] ?? null);
                }
                break;

            case 'Premia 2':
                if (is_array($slot)) {
                    $digits = array_values(array_filter(
                        array_map(fn ($n) => preg_replace('/\D+/', '', (string) $n), array_slice($slot, 0, 4)),
                        fn ($n) => $n !== ''
                    ));
                    for ($i = 0; $i + 1 < count($digits); $i += 2) {
                        $winningNumbers[] = $this->normalizeNumber($digits[$i] . $digits[$i + 1]);
                    }
                }
                break;

            case 'Jugá 3':
                if (is_array($slot)) {
                    foreach (array_slice($slot, 0, 3) as $n) {
                        $digit = preg_replace('/\D+/', '', (string) $n);
                        if ($digit !== '') {
                            $winningNumbers[] = $digit;
                        }
                    }
                }
                break;

            case 'Jugá 4':
                if (is_array($slot)) {
                    foreach (array_slice($slot, 0, 4) as $n) {
                        $digit = preg_replace('/\D+/', '', (string) $n);
                        if ($digit !== '') {
                            $winningNumbers[] = $digit;
                        }
                    }
                }
                break;

            case 'Fechas':
                if (is_array($slot) && isset($slot['numero'])) {
                    $winningNumbers[] = $this->normalizeNumber((string) $slot['numero']);
                    if (isset($slot['mes']) && $slot['mes'] !== '') {
                        $winningNumbers[] = (string) $slot['mes'];
                    }
                    $prizes = [
                        ['position' => 'Número', 'number' => $this->normalizeNumber((string) $slot['numero'])],
                    ];
                    if (isset($slot['mes']) && $slot['mes'] !== '') {
                        $prizes[] = ['position' => 'Mes', 'number' => (string) $slot['mes']];
                    }
                }
                break;

            case 'Terminación 2':
                if (is_string($slot) && $slot !== '') {
                    $winningNumbers[] = substr($this->normalizeNumber($slot), -2);
                } elseif (is_array($slot) && !empty($slot)) {
                    $num = $this->normalizeNumber((string) reset($slot));
                    $winningNumbers[] = substr($num, -2);
                }
                break;

            default:
                if (is_array($slot)) {
                    $winningNumbers = array_map(function ($n) {
                        return $this->normalizeNumber((string) $n);
                    }, array_slice($slot, 0, 2));
                }
        }

        if (empty($winningNumbers)) {
            return null;
        }

        return [
            'draw_date' => $date->format('Y-m-d'),
            'draw_time' => null,
            'winning_numbers' => $winningNumbers,
            'prizes' => $prizes,
            'draw_number' => null,
            'date_iso' => $date->format('Y-m-d'),
        ];
    }

    protected function buildDiariaPrizes($multiX, $mas1): ?array
    {
        $prizes = [];

        if (is_string($multiX) && preg_match('/^\d+\s*[xX]$/', trim($multiX))) {
            $prizes[] = ['position' => 'MULTI-X', 'number' => strtoupper(str_replace(' ', '', trim($multiX)))];
        }

        if (is_string($mas1) && ctype_digit(trim($mas1))) {
            $prizes[] = ['position' => 'MAS 1', 'number' => trim($mas1)];
        }

        return !empty($prizes) ? $prizes : null;
    }

    public function parseResult(array $rawData, string $gameName, string $drawTime): ?array
    {
        if (isset($rawData['winning_numbers']) && !empty($rawData['winning_numbers'])) {
            return [
                'draw_date' => $rawData['draw_date'] ?? now()->toDateString(),
                'draw_time' => $drawTime,
                'winning_numbers' => $rawData['winning_numbers'],
                'prizes' => $rawData['prizes'] ?? null,
                'draw_number' => $rawData['draw_number'] ?? null,
                'date_iso' => $rawData['date_iso'] ?? $rawData['draw_date'] ?? now()->toDateString(),
            ];
        }

        return null;
    }

    public function normalizeNumber(string $number): string
    {
        $num = trim($number);

        if (ctype_digit($num)) {
            return str_pad($num, 2, '0', STR_PAD_LEFT);
        }

        return $num;
    }
}
