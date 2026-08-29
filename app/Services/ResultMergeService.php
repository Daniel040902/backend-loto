<?php

namespace App\Services;

use App\Models\LotteryResult;
use App\Models\ManualResult;
use App\Support\DrawTime;
use Illuminate\Support\Collection;

class ResultMergeService
{
    /**
     * Combina resultados oficiales y manuales para la app principal.
     *
     * Regla de prioridad:
     *  - Si existe resultado OFICIAL para (país + juego + fecha + hora normalizada)
     *    se devuelve SIEMPRE el oficial.
     *  - Si NO existe oficial, se devuelve el MANUAL (provisional).
     *
     * Devuelve una Colección de arrays con la forma JSON que ya espera la app
     * (country, game, draw_date, draw_time, winning_numbers, prizes, etc.).
     */
    public function merge(Collection $officials, Collection $manuals): Collection
    {
        // Índice de claves de sorteos oficiales existentes (el oficial siempre manda)
        $officialKeys = [];
        foreach ($officials as $result) {
            $key = $this->resultKey($result);
            if ($key !== null) {
                $officialKeys[$key] = true;
            }
        }

        $merged = collect();

        foreach ($officials as $result) {
            $merged->push($this->toResponseArray($result));
        }

        foreach ($manuals as $manual) {
            $key = $this->manualKey($manual);
            if ($key !== null && isset($officialKeys[$key])) {
                // Ya existe oficial para ese sorteo: el oficial lo cubre.
                continue;
            }
            $merged->push($this->toManualArray($manual));
        }

        return $merged;
    }

    protected function resultKey(LotteryResult $result): ?string
    {
        $date = $result->draw_date instanceof \Illuminate\Support\Carbon
            ? $result->draw_date->format('Y-m-d')
            : (string) $result->draw_date;

        return DrawTime::sorteoKey(
            (int) $result->country_id,
            (int) $result->game_id,
            $date,
            $result->draw_time
        );
    }

    protected function manualKey(ManualResult $manual): ?string
    {
        $date = $manual->draw_date instanceof \Illuminate\Support\Carbon
            ? $manual->draw_date->format('Y-m-d')
            : (string) $manual->draw_date;

        return DrawTime::sorteoKey(
            (int) $manual->country_id,
            (int) $manual->game_id,
            $date,
            $manual->draw_time
        );
    }

    protected function toResponseArray(LotteryResult $result): array
    {
        $date = $result->draw_date instanceof \Illuminate\Support\Carbon
            ? $result->draw_date->format('Y-m-d')
            : (string) $result->draw_date;

        return [
            'id' => $result->id,
            'country_id' => $result->country_id,
            'game_id' => $result->game_id,
            'draw_date' => $date,
            'draw_time' => $result->draw_time,
            'winning_numbers' => $result->winning_numbers ?? [],
            'prizes' => $result->prizes,
            'draw_number' => $result->draw_number,
            'date_iso' => $result->date_iso ?? $date,
            'source' => $result->source ?? 'scraper',
            'created_at' => optional($result->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($result->updated_at)->format('Y-m-d H:i:s'),
            'country' => $result->country
                ? ['id' => $result->country->id, 'name' => $result->country->name, 'flag' => $result->country->flag, 'slug' => $result->country->slug]
                : null,
            'game' => $result->game
                ? ['id' => $result->game->id, 'name' => $result->game->name, 'slug' => null, 'type' => $result->game->type]
                : null,
        ];
    }

    protected function toManualArray(ManualResult $manual): array
    {
        $date = $manual->draw_date instanceof \Illuminate\Support\Carbon
            ? $manual->draw_date->format('Y-m-d')
            : (string) $manual->draw_date;

        return [
            'id' => $manual->id,
            'country_id' => $manual->country_id,
            'game_id' => $manual->game_id,
            'draw_date' => $date,
            'draw_time' => $manual->draw_time,
            'winning_numbers' => $manual->winning_numbers ?? [],
            'prizes' => $manual->prizes,
            'draw_number' => null,
            'date_iso' => $date,
            'source' => 'manual',
            'created_at' => optional($manual->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($manual->updated_at)->format('Y-m-d H:i:s'),
            'country' => $manual->country
                ? ['id' => $manual->country->id, 'name' => $manual->country->name, 'flag' => $manual->country->flag, 'slug' => $manual->country->slug]
                : null,
            'game' => $manual->game
                ? ['id' => $manual->game->id, 'name' => $manual->game->name, 'slug' => null, 'type' => $manual->game->type]
                : null,
        ];
    }
}
