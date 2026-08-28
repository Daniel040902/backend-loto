<?php

namespace App\Console\Commands;

use App\Jobs\SendCountryNotification;
use App\Models\Country;
use App\Models\Game;
use App\Models\LotteryResult;
use Illuminate\Console\Command;

class FirebaseTestCommand extends Command
{
    protected $signature = 'firebase:test';

    protected $description = 'PRUEBA: crea/actualiza un resultado de prueba por pais y despacha SendCountryNotification a la cola (flujo real completo)';

    public function handle(): int
    {
        $tests = [
            ['title' => 'PRUEBA Nicaragua', 'country_slug' => 'nicaragua', 'game_name' => 'La Diaria', 'draw_time' => '9:01 PM', 'numbers' => ['77']],
            ['title' => 'PRUEBA Costa Rica', 'country_slug' => 'costa-rica', 'game_name' => 'Nuevos Tiempos', 'draw_time' => '4:31 PM', 'numbers' => ['88']],
            ['title' => 'PRUEBA Guatemala', 'country_slug' => 'guatemala', 'game_name' => 'Pega 2', 'draw_time' => '9:01 PM', 'numbers' => ['66']],
            ['title' => 'PRUEBA Honduras', 'country_slug' => 'honduras', 'game_name' => 'La Diaria', 'draw_time' => '11:01 AM', 'numbers' => ['55']],
            ['title' => 'PRUEBA El Salvador', 'country_slug' => 'el-salvador', 'game_name' => 'La Diaria', 'draw_time' => '11:02 AM', 'numbers' => ['44']],
            ['title' => 'PRUEBA República Dominicana', 'country_slug' => 'republica-dominicana', 'game_name' => 'La Primera', 'draw_time' => '12:01 PM', 'numbers' => ['12', '34', '56']],
            ['title' => 'PRUEBA Belice', 'country_slug' => 'belice', 'game_name' => 'Boledo', 'draw_time' => '9:02 PM', 'numbers' => ['99']],
        ];

        $this->info('=== PRUEBA FCM POR TOPIC (TODOS LOS PAISES) ===');
        $this->line('');

        foreach ($tests as $test) {
            $this->info('>> ' . $test['title']);

            $country = Country::where('slug', $test['country_slug'])->first();
            if (!$country) {
                $this->error('  Pais no encontrado: ' . $test['country_slug']);
                $this->line('');
                continue;
            }

            $game = Game::where('country_id', $country->id)->where('name', $test['game_name'])->first();
            if (!$game) {
                $this->error('  Juego no encontrado: ' . $test['game_name'] . ' para ' . $country->name);
                $this->line('');
                continue;
            }

            $topic = $this->topicForCountry($country->slug);

            $result = LotteryResult::withoutEvents(fn () => LotteryResult::updateOrCreate(
                [
                    'game_id' => $game->id,
                    'draw_date' => now()->toDateString(),
                    'draw_time' => $test['draw_time'],
                ],
                [
                    'country_id' => $country->id,
                    'winning_numbers' => $test['numbers'],
                    'prizes' => null,
                    'draw_number' => 'TEST-' . now()->format('YmdHis'),
                    'date_iso' => now()->toDateTimeString(),
                    'source' => 'firebase_test',
                ]
            ));

            $body = $game->name . ' • ' . implode(', ', $test['numbers']) . ' • ' . $test['draw_time'];
            SendCountryNotification::dispatch($country, [$result->id], $test['title'], $body);

            $this->line('  Resultado guardado (ID: ' . $result->id . ' | ' . ($result->wasRecentlyCreated ? 'creado' : 'actualizado') . ')');
            $this->line('  SendCountryNotification despachado a la cola');
            $this->line('  Topic destino: ' . $topic);
            $this->line('');
        }

        $this->warn('Procesa la cola con: php artisan queue:work redis --stop-when-empty -vvv');
        $this->warn('Revisa los logs del worker para ver el status FCM por pais.');

        return self::SUCCESS;
    }

    protected function topicForCountry(string $slug): string
    {
        return [
            'nicaragua' => 'lottery_ni',
            'costa-rica' => 'lottery_cr',
            'guatemala' => 'lottery_gt',
            'honduras' => 'lottery_hn',
            'el-salvador' => 'lottery_sv',
            'republica-dominicana' => 'lottery_do',
            'belice' => 'lottery_bz',
        ][$slug] ?? 'desconocido';
    }
}
