<?php

namespace App\Providers;

use App\Services\LotteryScraper;
use App\Services\Scrapers\BelizeScraper;
use App\Services\Scrapers\CostaRicaScraper;
use App\Services\Scrapers\DominicanRepublicScraper;
use App\Services\Scrapers\ElSalvadorScraper;
use App\Services\Scrapers\GuatemalaScraper;
use App\Services\Scrapers\HondurasScraper;
use App\Services\Scrapers\NicaraguaScraper;
use App\Services\ScrapingService;
use Illuminate\Support\ServiceProvider;

class ScraperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScrapingService::class, function ($app) {
            $service = new ScrapingService;
            $service->registerScraper(new NicaraguaScraper);
            $service->registerScraper(new HondurasScraper);
            $service->registerScraper(new ElSalvadorScraper);
            $service->registerScraper(new GuatemalaScraper);
            $service->registerScraper(new CostaRicaScraper);
            $service->registerScraper(new DominicanRepublicScraper);
            $service->registerScraper(new BelizeScraper);
            return $service;
        });
    }

    public function boot(): void
    {
        //
    }
}
