<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualResult extends Model
{
    protected $table = 'manual_results';

    protected $fillable = [
        'country_id',
        'game_id',
        'draw_date',
        'draw_time',
        'winning_numbers',
        'prizes',
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

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
