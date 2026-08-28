<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BelizeScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://www.yelu.bz';

    protected array $games = [
        'Boledo' => ['name' => 'Boledo', 'draw_time' => '9:00 PM'],
        'Pick 3' => ['name' => 'Pick 3', 'draw_time' => '8:00 PM'],
        'Fantasy 5' => ['name' => 'Fantasy 5', 'draw_time' => '8:00 PM'],
    ];

    public function getCountrySlug(): string
    {
        return 'belice';
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
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($this->baseUrl . '/lottery/today-lottery-results');

            if (!$response->successful()) {
                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('LOTO BZ page failed: ' . $e->getMessage());
            return [];
        }

        $html = $response->body();
        $results = [];

        foreach ($this->games as $name => $config) {
            $parsed = $this->parseSection($html, $name, $date);
            if ($parsed) {
                $results[] = $parsed;
            }
        }

        return $results;
    }

    protected function parseSection(string $html, string $gameName, \DateTime $date): ?array
    {
        if (!preg_match('/<h3>[^<]*' . preg_quote($gameName, '/') . '[^<]*<\/h3>/i', $html, $h)) {
            return null;
        }

        $start = strpos($html, $h[0]);
        if ($start === false) {
            return null;
        }

        $end = strpos($html, '<div class="v2_lotto_links">', $start);
        if ($end === false) {
            $end = $start + 4000;
        }

        $section = substr($html, $start, $end - $start);

        if (!preg_match('/<time datetime="(\d{4}-\d{2}-\d{2})"/', $section, $m)) {
            return null;
        }

        $drawDate = $m[1];
        // No descartar resultados pasados: yelu.bz solo publica el sorteo más reciente
        // de cada juego. Únicamente se rechazan fechas futuras, que suelen ser placeholder del sitio.
        if ($drawDate > $date->format('Y-m-d')) {
            return null;
        }

        preg_match_all('/lotto_no_r v2_lotto_no[^"]*">([^<]+)</', $section, $m);
        $values = array_map('trim', $m[1]);

        $winningNumbers = [];
        $prizes = null;

        switch ($gameName) {
            case 'Boledo':
                $winningNumbers = array_map(fn ($v) => $this->normalizeNumber($v), $values);
                break;

            case 'Pick 3':
                $winningNumbers = [implode('', $values)];
                break;

            case 'Fantasy 5':
                foreach ($values as $v) {
                    if (ctype_digit($v)) {
                        $winningNumbers[] = $this->normalizeNumber($v);
                    } else {
                        $prizes = [['position' => 'LETRA', 'number' => $v]];
                    }
                }
                break;
        }

        if (empty($winningNumbers)) {
            return null;
        }

        return [
            'game_name' => $gameName,
            'draw_date' => $drawDate,
            'draw_time' => $this->games[$gameName]['draw_time'],
            'winning_numbers' => $winningNumbers,
            'prizes' => $prizes,
            'draw_number' => null,
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

    public function normalizeNumber(string $number): string
    {
        $num = trim($number);

        if (ctype_digit($num)) {
            return str_pad($num, 2, '0', STR_PAD_LEFT);
        }

        return $num;
    }
}
