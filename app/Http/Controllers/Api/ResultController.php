<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCountryNotification;
use App\Models\Country;
use App\Models\Game;
use App\Models\LotteryResult;
use App\Models\ManualResult;
use App\Services\ResultMergeService;
use App\Support\DrawTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ResultController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'country' => 'required|string',
                'game' => 'required|string',
                'draw_date' => 'required|date',
                'draw_time' => 'nullable|string|max:20',
                'winning_numbers' => 'required|array',
                'winning_numbers.*' => 'string',
                'prizes' => 'nullable|array',
                'draw_number' => 'nullable|string|max:50',
                'date_iso' => 'nullable|string|max:20',
                'source' => 'nullable|string|max:30',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => 'Validation error', 'errors' => $validator->errors()], 422);
            }

            $data = $validator->validated();

            $country = Country::where('slug', $data['country'])->where('active', true)->first();
            if (!$country) {
                return response()->json(['error' => 'Country not found or inactive'], 404);
            }

            $game = $country->games()->where('name', $data['game'])->where('active', true)->first();
            if (!$game) {
                return response()->json(['error' => 'Game not found for country'], 404);
            }

            $drawDate = Carbon::parse($data['draw_date'])->toDateString();
            $drawTime = $data['draw_time'] ?? null;

            // Los resultados manuales se guardan en una tabla separada (manual_results),
            // nunca en lottery_results, para no sobrescribir ni mezclar con los oficiales del scraper.
            $manual = ManualResult::updateOrCreate(
                [
                    'game_id' => $game->id,
                    'draw_date' => $drawDate,
                    'draw_time' => $drawTime,
                ],
                [
                    'country_id' => $country->id,
                    'winning_numbers' => $data['winning_numbers'],
                    'prizes' => $data['prizes'] ?? null,
                    'source' => $data['source'] ?? 'manual',
                ]
            );

            $this->handleManualNotification($manual, $country);

            return response()->json([
                'message' => $manual->wasRecentlyCreated ? 'Resultado manual creado' : 'Resultado manual actualizado',
                'created' => $manual->wasRecentlyCreated,
                'result' => $manual->load('country', 'game'),
            ], $manual->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $e) {
            Log::error('ResultController@store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    public function compare(string $countrySlug): JsonResponse
    {
        try {
            $country = Country::where('slug', $countrySlug)->where('active', true)->first();
            if (!$country) {
                return response()->json(['error' => 'Country not found or inactive'], 404);
            }

            $since = Carbon::today($country->timezone)->subDays(3)->toDateString();

            $manual = ManualResult::with('game')
                ->where('country_id', $country->id)
                ->whereDate('draw_date', '>=', $since)
                ->orderBy('draw_date', 'desc')
                ->get()
                ->map(fn($m) => [
                    'game_id' => $m->game_id,
                    'game_name' => $m->game->name ?? null,
                    'draw_date' => $m->draw_date->format('Y-m-d'),
                    'draw_time' => $m->draw_time,
                    'winning_numbers' => $m->winning_numbers,
                ]);

            $official = LotteryResult::with('game')
                ->where('country_id', $country->id)
                ->whereDate('draw_date', '>=', $since)
                ->latestFirst()
                ->get()
                ->map(fn($r) => [
                    'game_id' => $r->game_id,
                    'game_name' => $r->game->name ?? null,
                    'draw_date' => $r->draw_date->format('Y-m-d'),
                    'draw_time' => $r->draw_time,
                    'winning_numbers' => $r->winning_numbers,
                ]);

            return response()->json([
                'country' => $country,
                'manual' => $manual,
                'official' => $official,
            ]);
        } catch (\Throwable $e) {
            Log::error("ResultController@compare($countrySlug) failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = LotteryResult::with(['country', 'game'])
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->latestFirst();

            if ($request->filled('country')) {
                $query->whereHas('country', fn($q) => $q->where('slug', $request->country));
            }

            if ($request->filled('game')) {
                $query->whereHas('game', fn($q) => $q->where('name', $request->game));
            }

            if ($request->filled('date')) {
                $query->whereDate('draw_date', $request->date);
            }

            if ($request->filled('limit')) {
                $query->limit(min((int) $request->limit, 100));
            }

            $officials = $query->get();

            $manuals = $this->manualQuery($request)
                ->limit(min((int) $request->limit, 100) ?: 30)
                ->get();

            $merged = app(ResultMergeService::class)->merge($officials, $manuals);

            return response()->json($merged);
        } catch (\Throwable $e) {
            Log::error('ResultController@index failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    public function latest(): JsonResponse
    {
        try {
            $officials = LotteryResult::with(['country', 'game'])
                ->whereNotNull('country_id')
                ->whereNotNull('game_id')
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->orderBy('draw_date', 'desc')
                ->orderBy('updated_at', 'desc')
                ->limit(500)
                ->get()
                ->filter(fn($r) => $r->country && $r->game);

            $manuals = ManualResult::with(['country', 'game'])
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->orderBy('draw_date', 'desc')
                ->orderBy('updated_at', 'desc')
                ->limit(500)
                ->get()
                ->filter(fn($m) => $m->country && $m->game);

            // Fusiona: el oficial manda; los manuales sin oficial se rellenan.
            $merged = app(ResultMergeService::class)->merge($officials, $manuals)
                ->groupBy(fn($r) => $r['country']['slug'] ?? '')
                ->flatMap(function ($countryResults) {
                    return $countryResults
                        ->groupBy(fn($r) => $r['game']['id'] ?? 0)
                        ->flatMap(fn($gameResults) => $gameResults
                            ->sortByDesc(fn($r) => $this->sortDraw($r))
                            ->values()
                            ->take(4));
                })
                ->sortByDesc(fn($r) => $this->sortDraw($r))
                ->values();

            return response()->json($merged);
        } catch (\Throwable $e) {
            Log::error('ResultController@latest failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Clave de ordenación por fecha + hora normalizada a minutos,
     * para que el corte "4 últimos por juego" conserve el resultado más reciente
     * aunque venga de la tabla de manuales (agregados después del merge).
     */
    protected function sortDraw(array $row): string
    {
        $minutes = DrawTime::normalize($row['draw_time'] ?? null) ? $this->drawTimeMinutes($row['draw_time']) : 0;
        return ($row['draw_date'] ?? '') . 'T' . str_pad((string) $minutes, 4, '0', STR_PAD_LEFT);
    }

    protected function drawTimeMinutes(?string $time): int
    {
        if (!$time) return 0;
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($time), $m)) return 0;
        $h = ((int) $m[1]) % 12;
        if (strtoupper($m[3]) === 'PM') $h += 12;
        return $h * 60 + (int) $m[2];
    }

    public function byCountry(string $countrySlug): JsonResponse
    {
        try {
            $country = Country::where('slug', $countrySlug)->firstOrFail();

            $today = Carbon::today($country->timezone);

            $officials = LotteryResult::with('game')
                ->where('country_id', $country->id)
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->whereDate('draw_date', '>=', $today->copy()->subDays(2))
                ->latestFirst()
                ->limit(30)
                ->get();

            $manuals = ManualResult::with('game')
                ->where('country_id', $country->id)
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->whereDate('draw_date', '>=', $today->copy()->subDays(2))
                ->orderBy('draw_date', 'desc')
                ->limit(30)
                ->get();

            $merged = app(ResultMergeService::class)->merge($officials, $manuals);

            return response()->json($merged);
        } catch (\Throwable $e) {
            Log::error("ResultController@byCountry({$countrySlug}) failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    protected function manualQuery(Request $request)
    {
        $query = ManualResult::with(['country', 'game'])
            ->whereHas('game', fn($q) => $q->where('active', true))
            ->orderBy('draw_date', 'desc');

        if ($request->filled('country')) {
            $query->whereHas('country', fn($q) => $q->where('slug', $request->country));
        }

        if ($request->filled('game')) {
            $query->whereHas('game', fn($q) => $q->where('name', $request->game));
        }

        if ($request->filled('date')) {
            $query->whereDate('draw_date', $request->date);
        }

        return $query;
    }

    /**
     * Gestiona la notificación FCM de un resultado manual publicado.
     *
     * CASO 1: no existe oficial para el sorteo -> FCM del manual UNA vez.
     * CASO 2: existe oficial y coincide      -> no FCM (evita duplicado).
     * CASO 3: existe oficial y difiere       -> el oficial manda; sin FCM extra
     *         (la corrección se emite cuando el oficial llega por scraper).
     */
    protected function handleManualNotification(ManualResult $manual, Country $country): void
    {
        try {
            $manual->loadMissing('game');

            $official = LotteryResult::where('country_id', $manual->country_id)
                ->where('game_id', $manual->game_id)
                ->whereDate('draw_date', $manual->draw_date->format('Y-m-d'))
                ->get()
                ->first(
                    fn($r) => DrawTime::normalize($r->draw_time) === DrawTime::normalize($manual->draw_time)
                );

            $manual->official_checked_at = now();

            if (!$official) {
                // CASO 1: sin oficial aún -> notificar el manual una sola vez.
                $manual->winning_numbers = $manual->winning_numbers;
                if (!$manual->isNotified()) {
                    $manual->status = 'notified';
                    $manual->notified_at = now();
                    $manual->save();
                    $manual->refresh();
                    SendCountryNotification::dispatch($country, [], null, null, $manual);
                    Log::info("FCM manual enviado: sorteo={$manual->sorteoKey()}");
                } else {
                    $manual->save();
                }
                return;
            }

            // Existe oficial para el sorteo.
            if ($manual->winning_numbers === ($official->winning_numbers ?? [])) {
                // CASO 2: coincide -> sin FCM; el oficial cubre el sorteo.
                $manual->status = 'match';
                $manual->save();
                return;
            }

            // CASO 3: difiere -> el oficial manda; no se envía FCM del manual.
            $manual->status = 'correction';
            $manual->save();
        } catch (\Throwable $e) {
            Log::error('handleManualNotification failed: ' . $e->getMessage());
        }
    }
}
