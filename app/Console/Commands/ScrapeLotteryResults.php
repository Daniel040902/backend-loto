<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeCountryJob;
use App\Models\Country;
use Illuminate\Console\Command;

class ScrapeLotteryResults extends Command
{
    protected $signature = 'lottery:scrape {--country= : Scrape a specific country by slug}';
    protected $description = 'Scrape lottery results for all active countries';

    public function handle(): int
    {
        $query = Country::where('active', true);

        if ($countrySlug = $this->option('country')) {
            $query->where('slug', $countrySlug);
        }

        $countries = $query->get();

        if ($countries->isEmpty()) {
            $this->warn('No active countries found.');
            return self::SUCCESS;
        }

        $this->info("Dispatching scrape jobs for {$countries->count()} countries...");

        foreach ($countries as $country) {
            ScrapeCountryJob::dispatch($country);
            $this->line("  Dispatched: {$country->name}");
        }

        $this->info('All scrape jobs dispatched to queue.');
        return self::SUCCESS;
    }
}
