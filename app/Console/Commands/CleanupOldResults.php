<?php

namespace App\Console\Commands;

use App\Models\LotteryResult;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupOldResults extends Command
{
    protected $signature = 'lottery:cleanup {--days=90 : (desactivado) no se ejecuta el borrado de resultados}';
    protected $description = 'Borrado automático de resultados DESACTIVADO: el histórico se conserva indefinidamente';

    public function handle(): int
    {
        $this->warn('lottery:cleanup está DESACTIVADO. El histórico de resultados se conserva sin borrado automático.');

        // Preservamos la lógica original únicamente para auditoría, pero SIN ejecutar el borrado.
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);
        $wouldDelete = LotteryResult::where('draw_date', '<', $cutoff)->count();
        $this->info("No se borró nada. Se habrían eliminado {$wouldDelete} resultados anteriores a {$cutoff->format('Y-m-d')} si el cleanup estuviese activo.");

        return self::SUCCESS;
    }
}
