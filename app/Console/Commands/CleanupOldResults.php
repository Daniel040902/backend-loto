<?php

namespace App\Console\Commands;

use App\Models\LotteryResult;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupOldResults extends Command
{
    protected $signature = 'lottery:cleanup {--days=90 : Remove results older than this many days}';
    protected $description = 'Clean up old lottery results to save database space';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        $deleted = LotteryResult::where('draw_date', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} old results (before {$cutoff->format('Y-m-d')}).");
        return self::SUCCESS;
    }
}
