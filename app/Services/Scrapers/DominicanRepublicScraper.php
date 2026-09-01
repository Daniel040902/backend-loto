<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DominicanRepublicScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://www.loteriasdominicanas.us';

    protected array $pageConfigs = [
        ['url' => '/la-primera/quiniela/dia/', 'slug' => 'mediodia', 'draw_time' => '12:00 PM', 'game_name' => 'La Primera'],
        ['url' => '/la-primera/quiniela/noche/', 'slug' => 'noche', 'draw_time' => '8:00 PM', 'game_name' => 'La Primera'],
        ['url' => '/la-primera/quinielon/dia/', 'slug' => 'quinielon-dia', 'draw_time' => '12:00 PM', 'game_name' => 'Quinielón Día'],
        ['url' => '/la-primera/quinielon/noche/', 'slug' => 'quinielon-noche', 'draw_time' => '8:00 PM', 'game_name' => 'Quinielón Noche'],
        ['url' => '/la-primera/loto-5/', 'slug' => 'loto-5', 'draw_time' => '8:00 PM', 'game_name' => 'Loto 5'],
    ];

    protected array $spanishMonths = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    public function getCountrySlug(): string
    {
        return 'republica-dominicana';
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

        foreach ($this->pageConfigs as $config) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->get($this->baseUrl . $config['url'], ['d' => $date->format('Y-m-d')]);

                if (!$response->successful()) {
                    Log::warning("LOTO DO HTTP {$response->status()} for {$config['game_name']}");
                    continue;
                }

                $html = $response->body();
                $parsed = $this->parseCard($config, $html, $date);
                if ($parsed) {
                    $results[] = $parsed;
                }
            } catch (\Throwable $e) {
                Log::warning("LOTO DO failed for {$config['game_name']}: " . $e->getMessage());
            }
        }

        return $results;
    }

    protected function parseCard(array $config, string $html, \DateTime $date): ?array
    {
        if (!preg_match('/<div class="card[^"]*"[^>]*>.*?<ul class="balls">(.*?)<\/ul>/s', $html, $ballsMatch)) {
            return null;
        }

        if (!preg_match_all('/<li[^>]*>\s*(\d{1,3})\s*<\/li>/', $ballsMatch[1], $balls)) {
            return null;
        }

        $winningNumbers = [];
        foreach ($balls[1] as $number) {
            $winningNumbers[] = $this->normalizeNumber($number);
        }

        if (empty($winningNumbers)) {
            return null;
        }

        $drawDate = $date->format('Y-m-d');
        if (preg_match('/<div class="draw-date">([^<]+)<\/div>/s', $html, $d)) {
            $parsedDate = $this->parseSpanishDate(trim($d[1]));
            if ($parsedDate) {
                $drawDate = $parsedDate;
            }
        }

        $prizes = [];
        foreach ($winningNumbers as $i => $number) {
            $position = match ($i) {
                0 => 'Primer Premio',
                1 => 'Segundo Premio',
                2 => 'Tercer Premio',
                default => 'Número ' . ($i + 1),
            };
            $prizes[] = ['position' => $position, 'number' => $number];
        }

        return [
            'game_name' => $config['game_name'],
            'draw_date' => $drawDate,
            'draw_time' => $config['draw_time'],
            'winning_numbers' => $winningNumbers,
            'prizes' => $prizes,
            'draw_number' => null,
            'date_iso' => $drawDate,
        ];
    }

    protected function parseSpanishDate(string $dateText): ?string
    {
        if (preg_match('/^(\d{1,2})\s+([a-záéíóúñ]+)\s+(\d{4})$/i', trim($dateText), $m)) {
            $month = $this->spanishMonths[strtolower($m[2])] ?? null;
            if ($month !== null) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]);
            }
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
