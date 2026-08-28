<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HondurasScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://loto.hn';

    protected array $games = [
        'diaria' => ['name' => 'La Diaria'],
        'premia2' => ['name' => 'Premia 2'],
        'juga3' => ['name' => 'Jugá 3'],
        'pega3' => ['name' => 'Pega 3'],
    ];

    protected array $drawTimeMap = [
        '11' => '11:00 AM',
        '15' => '3:00 PM',
        '21' => '9:00 PM',
    ];

    public function getCountrySlug(): string
    {
        return 'honduras';
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
        $results = [];
        $dateStr = $date->format('Y-m-d');

        foreach ($this->games as $apiName => $config) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'application/json, text/plain, */*',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->get($this->baseUrl . "/api/resultados_{$apiName}_por_fecha.php", ['fecha' => $dateStr]);

                if (!$response->successful()) {
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    continue;
                }

                foreach ($data as $timeKey => $slot) {
                    if (empty($slot) || !is_array($slot) || !isset($this->drawTimeMap[$timeKey])) {
                        continue;
                    }

                    $parsed = $this->parseDrawSlot($slot, $config['name'], $date);
                    if ($parsed) {
                        if ($apiName === 'diaria') {
                            $multiplier = $this->fetchMultiplier($dateStr, (string) $timeKey);
                            if ($multiplier !== null) {
                                $parsed['prizes'] = array_merge($parsed['prizes'] ?? [], [
                                    ['position' => 'MULTI-X', 'number' => $multiplier],
                                ]);
                            }
                        }
                        $results[] = array_merge($parsed, [
                            'game_name' => $config['name'],
                            'draw_time' => $this->drawTimeMap[$timeKey],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("LOTO HN failed for {$apiName}: " . $e->getMessage());
            }
        }

        return $results;
    }

    protected function parseDrawSlot(array $slot, string $gameName, \DateTime $date): ?array
    {
        $winningNumbers = [];
        $prizes = null;

        switch ($gameName) {
            case 'La Diaria':
                $num = $slot['diaria']['par1'] ?? $slot['par1'] ?? null;
                if ($num !== null) {
                    $winningNumbers[] = $this->normalizeNumber((string) $num);
                }

                $mas1 = $slot['mas1']['par1'] ?? null;
                if ($mas1 !== null && $mas1 !== '' && $mas1 !== '0') {
                    $prizes = [['position' => 'MAS 1', 'number' => (string) $mas1]];
                }
                break;

            case 'Premia 2':
                foreach (['par1', 'par2'] as $key) {
                    if (isset($slot[$key])) {
                        $winningNumbers[] = $this->normalizeNumber((string) $slot[$key]);
                    }
                }
                break;

            case 'Jugá 3':
                if (isset($slot['par1'])) {
                    $winningNumbers[] = $this->normalizeNumber((string) $slot['par1']);
                }
                break;

            case 'Pega 3':
                foreach (['par1', 'par2', 'par3'] as $key) {
                    if (isset($slot[$key])) {
                        $winningNumbers[] = $this->normalizeNumber((string) $slot[$key]);
                    }
                }
                break;
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

    protected function fetchMultiplier(string $dateStr, string $timeKey): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($this->baseUrl . '/api/resultados_multix_por_fecha.php', ['fecha' => $dateStr]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            $slot = is_array($data) ? ($data[$timeKey] ?? null) : null;

            if (is_array($slot) && isset($slot['par2']) && $slot['par2'] !== '' && $slot['par2'] !== null) {
                return strtoupper(trim((string) $slot['par2']));
            }
        } catch (\Throwable $e) {
            Log::warning('LOTO HN multix multiplier: ' . $e->getMessage());
        }

        return null;
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
