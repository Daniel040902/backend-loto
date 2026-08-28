<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DominicanRepublicScraper implements LotteryScraper
{
    protected string $baseUrl = 'https://www.loteriasdominicanas.us';

    protected array $drawTimeMap = [
        'mediodia' => '12:00 PM',
        'noche' => '8:00 PM',
    ];

    protected array $gameNameMap = [
        'mediodia' => 'La Primera Mediodia',
        'noche' => 'La Primera Noche',
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

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($this->baseUrl . '/', ['d' => $date->format('Y-m-d')]);

            if (!$response->successful()) {
                Log::warning("LOTO DO HTTP {$response->status()} for {$date->format('Y-m-d')}");
                return [];
            }

            $html = $response->body();

            preg_match_all(
                '/<div class="card la-primera-([a-z]+)[^"]*".*?<ul class="balls">(.*?)<\/ul>/s',
                $html,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $sorteoSlug = $match[1];
                $parsed = $this->parseCard($sorteoSlug, $match[2], $date, $html);
                if ($parsed) {
                    $results[] = $parsed;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("LOTO DO failed: " . $e->getMessage());
        }

        return $results;
    }

    protected function parseCard(string $sorteoSlug, string $ballsHtml, \DateTime $date, string $fullHtml): ?array
    {
        if (!preg_match_all('/<li[^>]*>\s*(\d{1,3})\s*<\/li>/', $ballsHtml, $balls)) {
            return null;
        }

        $winningNumbers = [];
        foreach ($balls[1] as $number) {
            $winningNumbers[] = $this->normalizeNumber($number);
        }

        if (empty($winningNumbers)) {
            return null;
        }

        $drawTime = $this->drawTimeMap[$sorteoSlug] ?? null;
        if (!$drawTime) {
            return null;
        }

        $drawDate = $date->format('Y-m-d');
        if (preg_match('/class="card la-primera-' . $sorteoSlug . '[^"]*".*?<div class="draw-date">([^<]+)<\/div>/s', $fullHtml, $d)) {
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
                default => 'Premio ' . ($i + 1),
            };
            $prizes[] = ['position' => $position, 'number' => $number];
        }

        return [
            'game_name' => $this->gameNameMap[$sorteoSlug] ?? 'La Primera',
            'draw_date' => $drawDate,
            'draw_time' => $drawTime,
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
