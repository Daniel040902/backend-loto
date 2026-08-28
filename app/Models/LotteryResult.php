<?php

namespace App\Models;

use App\Jobs\SendCountryNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            if ($country) {
                SendCountryNotification::dispatch($country, [$result->id]);
            }
        });
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
