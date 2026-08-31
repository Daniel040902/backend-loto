<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lottery:scrape')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Recuperación automática del histórico: revisa SOLO los últimos 10 días
// una vez al día para completar fechas faltantes/incompletas (updateOrCreate).
// No debe correr cada minuto para no cargar servidor ni fuentes externas.
Schedule::command('lottery:backfill --days=10')
    ->dailyAt('01:30')
    ->withoutOverlapping(30)
    ->runInBackground();

