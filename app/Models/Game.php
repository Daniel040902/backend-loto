<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'country_id',
        'name',
        'type',
        'draw_times',
        'api_endpoint',
        'calendar_endpoint',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'draw_times' => 'array',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LotteryResult::class);
    }

    public function latestResult()
    {
        return $this->hasOne(LotteryResult::class)->latestOfMany();
    }
}
