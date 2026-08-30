<?php

namespace App\Models;

use App\Support\DrawTime;
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
        'notified_at',
        'notified_numbers',
        'official_checked_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'draw_date' => 'date',
            'winning_numbers' => 'array',
            'prizes' => 'array',
            'notified_at' => 'datetime',
            'notified_numbers' => 'array',
            'official_checked_at' => 'datetime',
        ];
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

    public function isNotified(): bool
    {
        return $this->notified_at !== null;
    }

    /**
     * ¿Los números actuales ya fueron notificados previamente?
     * Devuelve true si coincide exactamente (sin duplicar la notificación).
     */
    public function alreadyNotifiedWith(array $numbers): bool
    {
        $notified = $this->notified_numbers ?? [];
        if (empty($notified)) {
            return false;
        }
        sort($notified);
        $current = $numbers;
        sort($current);
        return $notified === $current;
    }

    /**
     * Marca los números como notificados (para detectar correcciones posteriores).
     */
    public function markNotifiedWith(array $numbers): void
    {
        $this->notified_numbers = array_values($numbers);
        $this->notified_at = now();
        $this->status = 'notified';
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
