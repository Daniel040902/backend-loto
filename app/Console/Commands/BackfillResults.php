<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\LotteryResult;
use App\Services\Scrapers\BelizeScraper;
use App\Services\Scrapers\CostaRicaScraper;
use App\Services\Scrapers\DominicanRepublicScraper;
use App\Services\Scrapers\ElSalvadorScraper;
use App\Services\Scrapers\GuatemalaScraper;
use App\Services\Scrapers\HondurasScraper;
use App\Services\Scrapers\NicaraguaScraper;
use App\Services\ScrapingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recuperación automática del histórico.
 *
 * Revisa SOLO los últimos N días (por defecto 10). Para cada país y día,
 * detecta si algún juego activo no tiene resultado guardado; si falta,
 * intenta recuperarlo con la lógica existente (ScrapingService::scrapeCountry,
 * que usa updateOrCreate: no duplica, no borra, no reemplaza resultados válidos).
 *
 * Los 100 días que la API puede consultar NO se revisan aquí; este proceso
 * está limitado estrictamente a los últimos 10 días.
 */
class BackfillResults extends Command
{
    protected $signature = 'lottery:backfill {--country= : Re-scrape a specific country by slug} {--days=10 : How many days back to review (max 10)}';

    protected $description = 'Revisa los últimos 10 días y recupera resultados faltantes/incompletos (updateOrCreate)';

    public function handle(ScrapingService $scrapingService): int
    {
        $days = max(1, (int) $this->option('days'));
        // Limite estricto: la recuperación automática NUNCA supera 10 días.
        $days = min($days, 10);
        $countrySlug = $this->option('country');

        $query = Country::where('active', true);
        if ($countrySlug) {
            $query->where('slug', $countrySlug);
        }

        $countries = $query->get();
        if ($countries->isEmpty()) {
            $this->warn('No active countries found.');
            return self::SUCCESS;
        }

        $from = Carbon::today()->subDays($days - 1);
        $this->info("Backfill: revisando últimos {$days} días (desde {$from->format('Y-m-d')} hasta " . Carbon::today()->format('Y-m-d') . ")");

        $totalMissing = 0;
        $totalRecovered = 0;

        // Los resultados históricos recuperados NO deben disparar notificaciones FCM.
        \App\Models\LotteryResult::$suppressCreatedNotification = true;

        try {
            foreach ($countries as $country) {
                $scraper = $this->resolveScraper($country->slug);
                if (!$scraper) {
                    $this->warn("No scraper for {$country->slug}, skipping.");
                    continue;
                }

                $scrapingService->registerScraper($scraper);
                $activeGames = $country->games()->where('active', true)->pluck('name');

                for ($date = $from->copy(); $date->lte(Carbon::today()); $date->addDay()) {
                    $missingGames = $this->missingGamesFor($country->id, $activeGames, $date->toDateString());
                    if ($missingGames->isEmpty()) {
                        continue; // día completo, no hace falta recuperar
                    }

                    $totalMissing += $missingGames->count();
                    $this->line("  {$country->slug} {$date->format('Y-m-d')}: faltan juegos [" . $missingGames->implode(', ') . '] -> intentando recuperar');

                    try {
                        $saved = $scrapingService->scrapeCountry($scraper, $date->copy());
                        $totalRecovered += count($saved);
                        $this->line("      recuperados: " . count($saved));
                    } catch (\Throwable $e) {
                        Log::error("lottery:backfill fallo para {$country->slug} {$date->format('Y-m-d')}: " . $e->getMessage());
                        $this->error("      error: {$e->getMessage()}");
                    }
                }
            }

            $this->info("Backfill terminado. Juegos faltantes detectados: {$totalMissing}. Intentos de recuperación: {$totalMissing}, registros guardados/actualizados: {$totalRecovered}.");
        } finally {
            // Siempre se restablece, aunque haya excepciones.
            \App\Models\LotteryResult::$suppressCreatedNotification = false;
        }

        return self::SUCCESS;
    }

    /**
     * Juegos activos del país que NO tienen resultado guardado para la fecha dada.
     */
    protected function missingGamesFor(int $countryId, $activeGames, string $date): \Illuminate\Support\Collection
    {
        $present = LotteryResult::with('game')
            ->where('country_id', $countryId)
            ->whereDate('draw_date', $date)
            ->whereHas('game', fn($q) => $q->where('active', true))
            ->get()
            ->filter(function ($r) {
                $numbers = $r->winning_numbers;
                return is_array($numbers) && count($numbers) > 0;
            })
            ->map(fn($r) => $r->game->name)
            ->flip();

        return $activeGames->reject(fn($name) => $present->has($name))->values();
    }

    protected function resolveScraper(string $slug): ?object
    {
        return match ($slug) {
            'nicaragua' => new NicaraguaScraper,
            'honduras' => new HondurasScraper,
            'el-salvador' => new ElSalvadorScraper,
            'guatemala' => new GuatemalaScraper,
            'costa-rica' => new CostaRicaScraper,
            'republica-dominicana' => new DominicanRepublicScraper,
            'belice' => new BelizeScraper,
            default => null,
        };
    }
}
