<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GuatemalaScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://lotto.gt';

    protected array $games = [
        'gg-world-pega-4-guatemala' => ['name' => 'Pega 4'],
        'gg-world-pega-3-guatemala' => ['name' => 'Pega 3'],
        'gg-world-pega-2-guatemala' => ['name' => 'Pega 2'],
        'nap-2-guatemala' => ['name' => 'Nap 2'],
    ];

    public function getCountrySlug(): string
    {
        return 'guatemala';
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
        $minDate = (clone $date)->modify('-3 days')->format('Y-m-d');
        $maxDate = $date->format('Y-m-d');

        foreach ($this->games as $slug => $config) {
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept-Language' => 'es-GT,es;q=0.9,en;q=0.8',
                    ])
                    ->get($this->baseUrl . "/resultados/{$slug}");

                if (!$response->successful()) {
                    continue;
                }

                $parsed = $this->parsePage($response->body(), $config['name']);
                if (!$parsed) {
                    continue;
                }

                $drawDate = $parsed['draw_date'];
                if ($drawDate < $minDate || $drawDate > $maxDate) {
                    continue;
                }

                $results[] = array_merge($parsed, [
                    'game_name' => $config['name'],
                    'draw_time' => $parsed['draw_time'],
                ]);
            } catch (\Throwable $e) {
                Log::warning("LOTO GT failed for {$slug}: " . $e->getMessage());
            }
        }

        return $results;
    }

    protected function parsePage(string $html, ?string $gameName = null): ?array
    {
        if (!preg_match('/data-draw-numbers="([^"]+)"/', $html, $m)) {
            return null;
        }

        $json = json_decode(html_entity_decode(trim($m[1])), true);
        if (!is_array($json)) {
            return null;
        }

        $twoDigit = in_array($gameName, ['Pega 2', 'Nap 2'], true);

        $flat = [];
        foreach ($json as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $n) {
                if (is_int($n) || is_numeric($n)) {
                    $flat[] = trim((string) $n);
                }
            }
        }

        if ($twoDigit) {
            $flat = $this->groupPairs($flat);
        }

        $numbers = array_map(fn($n) => $this->normalizeNumber($n), $flat);

        if (empty($numbers)) {
            return null;
        }

        $drawDate = now()->toDateString();
        $drawTime = null;
        if (preg_match('/data-draw-date="([^"]+)"/', $html, $d)) {
            try {
                $dt = Carbon::parse($d[1])->setTimezone('America/Guatemala');
                $drawDate = $dt->toDateString();
                $drawTime = $dt->format('g:i A');
            } catch (\Throwable $e) {
                // mantener fecha/hora por defecto
            }
        }

        $drawNo = null;
        if (preg_match('/data-draw-no="([^"]+)"/', $html, $n)) {
            $drawNo = $n[1];
        }

        return [
            'draw_date' => $drawDate,
            'draw_time' => $drawTime,
            'winning_numbers' => $numbers,
            'prizes' => null,
            'draw_number' => $drawNo,
            'date_iso' => $drawDate,
        ];
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

    /**
     * Agrupa dígitos sueltos (un carácter) de a dos para formar números de dos
     * dígitos. Ej: ["3","6"] => ["36"]. Los números ya formados (dos o más
     * caracteres) se mantienen tal cual, ej: ["23","50"] => ["23","50"].
     */
    protected function groupPairs(array $flat): array
    {
        $out = [];
        $count = count($flat);
        for ($i = 0; $i < $count; $i++) {
            $cur = $flat[$i];
            if (strlen($cur) === 1 && isset($flat[$i + 1]) && strlen($flat[$i + 1]) === 1) {
                $out[] = $cur . $flat[$i + 1];
                $i++;
            } else {
                $out[] = $cur;
            }
        }

        return $out;
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
