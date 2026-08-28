<?php

namespace App\Jobs;

use App\Models\Country;
use App\Services\Scrapers\BelizeScraper;
use App\Services\Scrapers\CostaRicaScraper;
use App\Services\Scrapers\DominicanRepublicScraper;
use App\Services\Scrapers\ElSalvadorScraper;
use App\Services\Scrapers\GuatemalaScraper;
use App\Services\Scrapers\HondurasScraper;
use App\Services\Scrapers\NicaraguaScraper;
use App\Services\ScrapingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeCountryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    protected Country $country;

    public function __construct(Country $country)
    {
        $this->country = $country;
    }

    public function handle(ScrapingService $scrapingService): void
    {
        try {
            $availableAt = $this->job?->availableAt();
        } catch (\Throwable $e) {
            $availableAt = null;
        }
        Log::warning('FCM-DIAG scrape job inicio', [
            'country' => $this->country->slug,
            't_start' => now()->format('Y-m-d H:i:s.v'),
            'queued_delay_s' => $availableAt ? round(max(0, now()->timestamp - (int) $availableAt), 3) : null,
            'attempts' => $this->attempts(),
        ]);

        $scraper = $this->resolveScraper($this->country->slug);

        if (!$scraper) {
            Log::warning("No scraper found for country: {$this->country->slug}");
            return;
        }

        $scrapingService->registerScraper($scraper);
        $result = $scrapingService->scrapeCountry($scraper, now());

        Log::info("Scraped {$this->country->name}: " . count($result) . ' results');

        // El push FCM se dispara solo cuando se crea un resultado nuevo
        // (evento 'created' en el modelo LotteryResult)
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
