<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElSalvadorScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://loto.sv';

    protected array $games = [
        'diaria' => ['name' => 'La Diaria'],
    ];

    protected array $drawTimeMap = [
        '11' => '11:00 AM',
        '18' => '6:00 PM',
        '21' => '9:00 PM',
    ];

    public function getCountrySlug(): string
    {
        return 'el-salvador';
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
                    ->get($this->baseUrl . "/api/resultados_{$apiName}_sv.php", ['fecha' => $dateStr]);

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

                    $number = $slot['par1'] ?? null;
                    if ($number === null) {
                        continue;
                    }

                    $results[] = [
                        'game_name' => $config['name'],
                        'draw_date' => $dateStr,
                        'draw_time' => $this->drawTimeMap[$timeKey],
                        'winning_numbers' => [$this->normalizeNumber((string) $number)],
                        'prizes' => null,
                        'draw_number' => null,
                        'date_iso' => $dateStr,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("LOTO SV failed for {$apiName}: " . $e->getMessage());
            }
        }

        return $results;
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
