<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lottery:scrape')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('lottery:cleanup')
    ->daily();
