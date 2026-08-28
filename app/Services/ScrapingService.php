<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Game;
use App\Models\LotteryResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapingService
{
    protected array $scrapers = [];

    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function registerScraper(LotteryScraper $scraper): void
    {
        $this->scrapers[$scraper->getCountrySlug()] = $scraper;
    }

    public function scrapeAll(): array
    {
        $results = [];
        $date = now();

        foreach ($this->scrapers as $slug => $scraper) {
            try {
                $countryResults = $this->scrapeCountry($scraper, $date);
                $results[$slug] = [
                    'success' => true,
                    'count' => count($countryResults),
                ];
                Log::info("Scraped {$slug}: " . count($countryResults) . ' results');
            } catch (\Throwable $e) {
                Log::error("Failed to scrape {$slug}: " . $e->getMessage());
                $results[$slug] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function scrapeCountry(LotteryScraper $scraper, \DateTime $date): array
    {
        $country = Country::where('slug', $scraper->getCountrySlug())->first();
        if (!$country || !$country->active) {
            return [];
        }

        $rawResults = $scraper->fetchResults($date);
        $saved = [];
        $games = $country->games()->where('active', true)->get()->keyBy('name');

        foreach ($rawResults as $raw) {
            $gameName = $raw['game_name'] ?? '';
            $drawTime = $raw['draw_time'] ?? '';

            $game = $games->first(fn($g) => $g->name === $gameName)
                ?? $games->first(function ($g) use ($gameName) {
                    return stripos($gameName, $g->name) !== false || stripos($g->name, $gameName) !== false;
                });

            if (!$game) {
                continue;
            }

            $parsed = $scraper->parseResult($raw, $gameName, $drawTime);
            if (!$parsed) {
                continue;
            }

            $result = LotteryResult::updateOrCreate(
                [
                    'game_id' => $game->id,
                    'draw_date' => $parsed['draw_date'],
                    'draw_time' => $parsed['draw_time'],
                ],
                [
                    'country_id' => $country->id,
                    'winning_numbers' => $parsed['winning_numbers'],
                    'prizes' => $parsed['prizes'] ?? null,
                    'draw_number' => $parsed['draw_number'] ?? null,
                    'date_iso' => $parsed['date_iso'] ?? $parsed['draw_date'],
                    'source' => 'api',
                ]
            );

            $saved[] = $result;

            Log::warning('FCM-DIAG resultado guardado (deteccion+postgres)', [
                'country' => $country->slug,
                'game' => $gameName,
                'draw_date' => $parsed['draw_date'],
                'draw_time' => $parsed['draw_time'],
                'result_id' => $result->id,
                't_save' => now()->format('Y-m-d H:i:s.v'),
                'was_recently_created' => $result->wasRecentlyCreated,
                'created_at' => optional($result->created_at)->format('Y-m-d H:i:s.v'),
                'updated_at' => optional($result->updated_at)->format('Y-m-d H:i:s.v'),
                'numbers' => $parsed['winning_numbers']
                    ? implode(',', array_slice($parsed['winning_numbers'], 0, 6))
                    : '(vacio)',
                'push' => $result->wasRecentlyCreated
                    ? (empty($parsed['winning_numbers'])
                        ? 'created_sin_numeros -> SendCountryNotification NO encolado'
                        : 'created_con_numeros -> SendCountryNotification encolado')
                    : 'actualizado (updateOrCreate) -> NO se encola push (solo evento created)',
            ]);
        }

        return $saved;
    }

    protected function fetchJson(string $url, array $params = []): ?array
    {
        $response = Http::timeout(15)
            ->withUserAgent($this->userAgent)
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => 'es-NI,es;q=0.9,en;q=0.8',
            ])
            ->get($url, $params);

        if (!$response->successful()) {
            Log::warning("HTTP {$response->status()} for {$url}");
            return null;
        }

        return $response->json();
    }

    protected function fetchHtml(string $url): ?string
    {
        $response = Http::timeout(15)
            ->withUserAgent($this->userAgent)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-NI,es;q=0.9,en;q=0.8',
            ])
            ->get($url);

        if (!$response->successful()) {
            Log::warning("HTTP {$response->status()} for {$url}");
            return null;
        }

        return $response->body();
    }
}
