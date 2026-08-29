<?php

namespace App\Models;

use App\Jobs\SendCountryNotification;
use App\Models\ManualResult;
use App\Support\DrawTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class LotteryResult extends Model
{
    protected $table = 'lottery_results';

    protected $fillable = [
        'country_id',
        'game_id',
        'draw_date',
        'draw_time',
        'winning_numbers',
        'prizes',
        'draw_number',
        'date_iso',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'draw_date' => 'date',
            'winning_numbers' => 'array',
            'prizes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (LotteryResult $result) {
            if (empty($result->winning_numbers) || !$result->country_id) {
                return;
            }

            $country = $result->country()->first();
            if (!$country) {
                return;
            }

            // CLAVE de sorteo: país + juego + fecha + hora normalizada.
            $manual = ManualResult::where('country_id', $result->country_id)
                ->where('game_id', $result->game_id)
                ->whereDate('draw_date', $result->draw_date->format('Y-m-d'))
                ->get()
                ->first(fn($m) => DrawTime::normalize($m->draw_time) === DrawTime::normalize($result->draw_time));

            // Sin manual para este sorteo -> FCM normal del scraper (CASO 4).
            if (!$manual) {
                SendCountryNotification::dispatch($country, [$result->id]);
                return;
            }

            $manual->official_checked_at = now();
            $manual->winning_numbers = $result->winning_numbers;
            $manual->prizes = $result->prizes;

            // El manual coincide con el oficial -> el oficial manda en la API,
            // y NO se manda otra FCM normal (evita duplicados) (CASO 2).
            if ($manual->isNotified() && $manual->winning_numbers === $result->winning_numbers) {
                $manual->status = 'match';
                $manual->save();
                Log::info("FCM suprimido (coincide con manual): sorteo={$manual->sorteoKey()}");
                return;
            }

            // El manual DIFIERE del oficial -> el oficial reemplaza y se manda
            // UNA notificación de corrección (CASO 3).
            if ($manual->isNotified()) {
                $manual->status = 'correction';
                $manual->save();

                $game = $result->game()->first();
                $title = 'Resultado corregido';
                $body = sprintf(
                    '%s %s - %s',
                    $game->name ?? 'Lotería',
                    $result->draw_time ?? '',
                    implode(',', $result->winning_numbers ?? [])
                );
                SendCountryNotification::dispatch($country, [$result->id], $title, $body);
                return;
            }

            // Manual existe pero aún no notificado: se guarda el estado, y como no
            // se notificó manual, el FCM normal del oficial es el correcto.
            $manual->status = 'match';
            $manual->save();
            SendCountryNotification::dispatch($country, [$result->id]);
        });
    }

    public function sorteoKey(): string
    {
        return DrawTime::sorteoKey(
            (int) $this->country_id,
            (int) $this->game_id,
            $this->draw_date->format('Y-m-d'),
            $this->draw_time
        );
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('draw_date', 'desc')->orderBy('created_at', 'desc');
    }

    public function scopeForGame($query, Game $game)
    {
        return $query->where('game_id', $game->id);
    }
}
