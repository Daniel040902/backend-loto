<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Game;
use App\Models\LotteryResult;
use App\Models\ManualResult;
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

            $results = $query->get();

            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('ResultController@index failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    public function latest(): JsonResponse
    {
        try {
            $results = LotteryResult::with(['country', 'game'])
                ->whereNotNull('country_id')
                ->whereNotNull('game_id')
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->orderBy('draw_date', 'desc')
                ->orderBy('updated_at', 'desc')
                ->limit(500)
                ->get()
                ->filter(fn($r) => $r->country && $r->game)
                ->groupBy(fn($r) => $r->country->slug)
                ->flatMap(function ($countryResults) {
                    return $countryResults
                        ->groupBy(fn($r) => $r->game_id)
                        ->flatMap(fn($gameResults) => $gameResults->take(4));
                })
                ->sortByDesc('draw_date')
                ->values();

            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('ResultController@latest failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }

    public function byCountry(string $countrySlug): JsonResponse
    {
        try {
            $country = Country::where('slug', $countrySlug)->firstOrFail();

            $today = Carbon::today($country->timezone);

            $results = LotteryResult::with('game')
                ->where('country_id', $country->id)
                ->whereHas('game', fn($q) => $q->where('active', true))
                ->whereDate('draw_date', '>=', $today->copy()->subDays(2))
                ->latestFirst()
                ->limit(30)
                ->get();

            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error("ResultController@byCountry({$countrySlug}) failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal error', 'message' => $e->getMessage()], 500);
        }
    }
}
