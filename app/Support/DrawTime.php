<?php

namespace App\Support;

class DrawTime
{
    /**
     * Normaliza una hora de sorteo a un formato canónico unificado.
     *
     * Ejemplos equivalentes -> "15:00":
     *   "3:00 PM", "03:00 PM", "3 PM", "15:00", "3:00pm", " 3:00 PM "
     */
    public static function normalize(?string $drawTime): ?string
    {
        if ($drawTime === null || trim($drawTime) === '') {
            return null;
        }

        $clean = strtoupper(trim($drawTime));

        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/', $clean, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $meridiem = $m[3] ?? null;

            if ($meridiem === 'PM' && $hours !== 12) {
                $hours += 12;
            } elseif ($meridiem === 'AM' && $hours === 12) {
                $hours = 0;
            }

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        // Formato sin minutos: "3 PM" o "15"
        if (preg_match('/^(\d{1,2})\s*(AM|PM)?$/', $clean, $m)) {
            $hours = (int) $m[1];
            $meridiem = $m[2] ?? null;
            if ($meridiem === 'PM' && $hours !== 12) {
                $hours += 12;
            } elseif ($meridiem === 'AM' && $hours === 12) {
                $hours = 0;
            }
            return sprintf('%02d:00', $hours);
        }

        // Formato 24h ya normalizado "15:00"
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $clean, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $drawTime;
    }

    /**
     * Clave canónica de sorteo: country_id + game_id + draw_date + hora normalizada.
     */
    public static function sorteoKey(int $countryId, int $gameId, string $drawDate, ?string $drawTime): string
    {
        return $countryId . '|'
            . $gameId . '|'
            . $drawDate . '|'
            . (string) self::normalize($drawTime);
    }
}
