<?php

namespace App\Console\Commands;

use App\Models\Country;
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

class RepairResults extends Command
{
    protected $signature = 'lottery:repair {--country= : Re-scrape a specific country by slug} {--days=7 : How many days back to re-scrape}';

    protected $description = 'Re-scrape recent past dates (updateOrCreate) to fix stored results';

    public function handle(ScrapingService $scrapingService): int
    {
        $days = max(0, (int) $this->option('days'));
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

        $from = Carbon::today()->subDays($days);
        $total = 0;

        foreach ($countries as $country) {
            $scraper = $this->resolveScraper($country->slug);
            if (!$scraper) {
                $this->warn("No scraper for {$country->slug}, skipping.");
                continue;
            }

            $scrapingService->registerScraper($scraper);

            for ($date = $from->copy(); $date->lte(Carbon::today()); $date->addDay()) {
                $saved = $scrapingService->scrapeCountry($scraper, $date->copy());
                $this->line("  {$country->slug} {$date->format('Y-m-d')}: " . count($saved) . ' results');
                $total += count($saved);
            }
        }

        $this->info("Done. $total results updated.");
        return self::SUCCESS;
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
