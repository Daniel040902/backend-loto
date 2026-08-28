<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Game;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        Country::upsert([
            ['name' => 'Nicaragua', 'slug' => 'nicaragua', 'flag' => '🇳🇮', 'operator' => 'LOTO Nicaragua', 'official_url' => 'https://loto.com.ni', 'timezone' => 'America/Managua', 'sort_order' => 1, 'active' => true],
            ['name' => 'Honduras', 'slug' => 'honduras', 'flag' => '🇭🇳', 'operator' => 'Loterías de Honduras', 'official_url' => 'https://www.loterias.hn', 'timezone' => 'America/Tegucigalpa', 'sort_order' => 2, 'active' => true],
            ['name' => 'El Salvador', 'slug' => 'el-salvador', 'flag' => '🇸🇻', 'operator' => 'Lotería Nacional de El Salvador', 'official_url' => 'https://www.loteria.com.sv', 'timezone' => 'America/El_Salvador', 'sort_order' => 3, 'active' => true],
            ['name' => 'Guatemala', 'slug' => 'guatemala', 'flag' => '🇬🇹', 'operator' => 'Loterías de Guatemala', 'official_url' => 'https://www.loterias.com.gt', 'timezone' => 'America/Guatemala', 'sort_order' => 4, 'active' => true],
            ['name' => 'Costa Rica', 'slug' => 'costa-rica', 'flag' => '🇨🇷', 'operator' => 'Junta de Protección Social', 'official_url' => 'https://www.loterias.co.cr', 'timezone' => 'America/Costa_Rica', 'sort_order' => 5, 'active' => true],
            ['name' => 'República Dominicana', 'slug' => 'republica-dominicana', 'flag' => '🇩🇴', 'operator' => 'La Primera', 'official_url' => 'https://www.loteriasdominicanas.us/la-primera/', 'timezone' => 'America/Santo_Domingo', 'sort_order' => 6, 'active' => true],
            ['name' => 'Belice', 'slug' => 'belice', 'flag' => '🇧🇿', 'operator' => 'Belize Lottery Commission', 'official_url' => 'https://www.belizelottery.com', 'timezone' => 'America/Belize', 'sort_order' => 7, 'active' => true],
        ], ['slug'], ['name', 'flag', 'operator', 'official_url', 'timezone', 'sort_order', 'active']);
    }
}
