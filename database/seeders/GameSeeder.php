<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            'nicaragua' => [
                ['name' => 'La Diaria', 'type' => 'two_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '6:00 PM', '9:00 PM'], 'sort_order' => 1],
                ['name' => 'Premia 2', 'type' => 'two_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '6:00 PM', '9:00 PM'], 'sort_order' => 2],
                ['name' => 'Jugá 3', 'type' => 'three_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '6:00 PM', '9:00 PM'], 'sort_order' => 3],
                ['name' => 'Jugá 4', 'type' => 'four_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '6:00 PM', '9:00 PM'], 'sort_order' => 4],
                ['name' => 'Fechas', 'type' => 'date_based', 'draw_times' => ['11:00 AM', '3:00 PM', '6:00 PM', '9:00 PM'], 'sort_order' => 5],
                ['name' => 'Terminación 2', 'type' => 'two_digit', 'draw_times' => ['6:00 PM'], 'sort_order' => 6],
            ],
            'honduras' => [
                ['name' => 'La Diaria', 'type' => 'two_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '9:00 PM'], 'sort_order' => 1],
                ['name' => 'Premia 2', 'type' => 'two_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '9:00 PM'], 'sort_order' => 2],
                ['name' => 'Jugá 3', 'type' => 'three_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '9:00 PM'], 'sort_order' => 3],
                ['name' => 'Pega 3', 'type' => 'three_digit', 'draw_times' => ['11:00 AM', '3:00 PM', '9:00 PM'], 'sort_order' => 4],
            ],
            'el-salvador' => [
                ['name' => 'La Diaria', 'type' => 'two_digit', 'draw_times' => ['11:00 AM', '9:00 PM'], 'sort_order' => 1],
            ],
            'guatemala' => [
                ['name' => 'Pega 4', 'type' => 'four_digit', 'draw_times' => ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM'], 'sort_order' => 1],
                ['name' => 'Pega 3', 'type' => 'three_digit', 'draw_times' => ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM'], 'sort_order' => 2],
                ['name' => 'Pega 2', 'type' => 'two_digit', 'draw_times' => ['8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM'], 'sort_order' => 3],
                ['name' => 'Nap 2', 'type' => 'two_digit', 'draw_times' => ['6:00 AM', '6:30 AM', '7:00 AM', '7:30 AM', '8:00 AM', '8:30 AM', '9:00 AM', '9:30 AM', '10:00 AM', '10:30 AM', '11:00 AM', '11:30 AM', '12:00 PM', '12:30 PM', '1:00 PM', '1:30 PM', '2:00 PM', '2:30 PM', '3:00 PM', '3:30 PM', '4:00 PM', '4:30 PM', '5:00 PM', '5:30 PM', '6:00 PM', '6:30 PM', '7:00 PM', '7:30 PM', '8:00 PM', '8:30 PM', '9:00 PM', '9:30 PM', '10:00 PM', '10:30 PM', '11:00 PM'], 'sort_order' => 4],
            ],
            'costa-rica' => [
                ['name' => 'Nuevos Tiempos', 'type' => 'two_digit', 'draw_times' => ['12:55 PM', '4:30 PM', '7:30 PM'], 'sort_order' => 1],
                ['name' => 'Tres Monazos', 'type' => 'three_digit', 'draw_times' => ['12:55 PM', '4:30 PM', '7:30 PM'], 'sort_order' => 2],
                ['name' => 'Lotto', 'type' => 'five_number', 'draw_times' => ['7:30 PM'], 'sort_order' => 3],
                ['name' => 'Lotto Revancha', 'type' => 'five_number', 'draw_times' => ['7:30 PM'], 'sort_order' => 4],
                ['name' => 'Chances', 'type' => 'two_digit', 'draw_times' => ['7:30 PM'], 'sort_order' => 5],
                ['name' => 'Lotería Nacional', 'type' => 'two_digit', 'draw_times' => ['7:30 PM'], 'sort_order' => 6],
            ],
            'republica-dominicana' => [
                ['name' => 'La Primera', 'type' => 'three_digit', 'draw_times' => ['12:00 PM', '8:00 PM'], 'sort_order' => 1],
                ['name' => 'Quinielón Día', 'type' => 'two_digit', 'draw_times' => ['12:00 PM'], 'sort_order' => 2],
                ['name' => 'Quinielón Noche', 'type' => 'two_digit', 'draw_times' => ['8:00 PM'], 'sort_order' => 3],
                ['name' => 'Loto 5', 'type' => 'five_number', 'draw_times' => ['8:00 PM'], 'sort_order' => 4],
            ],
            'belice' => [
                ['name' => 'Boledo', 'type' => 'two_digit', 'draw_times' => ['9:00 PM'], 'sort_order' => 1],
                ['name' => 'Pick 3', 'type' => 'three_digit', 'draw_times' => ['8:00 PM'], 'sort_order' => 2],
                ['name' => 'Fantasy 5', 'type' => 'five_number', 'draw_times' => ['8:00 PM'], 'sort_order' => 3],
            ],
        ];

        foreach ($games as $slug => $gameList) {
            $country = Country::where('slug', $slug)->first();
            if (!$country) {
                continue;
            }

            $country->games()->update(['active' => false]);

            foreach ($gameList as $game) {
                Game::updateOrCreate(
                    ['country_id' => $country->id, 'name' => $game['name']],
                    [
                        'type' => $game['type'],
                        'draw_times' => $game['draw_times'],
                        'sort_order' => $game['sort_order'],
                        'active' => true,
                    ]
                );
            }
        }
    }
}
