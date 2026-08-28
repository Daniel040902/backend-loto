<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\LotteryResult;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $user = $request->user();

        $totalCountries = Country::where('active', true)->count();
        $todayResults = LotteryResult::whereDate('draw_date', $today)->count();
        $recentResults = LotteryResult::with(['country', 'game'])
            ->latestFirst()
            ->limit(20)
            ->get();

        $perCountry = Country::active()->get()->map(function ($country) use ($today) {
            $tz = Carbon::today($country->timezone);
            $resultsToday = LotteryResult::where('country_id', $country->id)
                ->whereDate('draw_date', $tz)
                ->count();
            $latest = LotteryResult::where('country_id', $country->id)
                ->with('game')
                ->latestFirst()
                ->first();

            return [
                'country' => $country->only(['name', 'slug', 'flag']),
                'results_today' => $resultsToday,
                'latest_result' => $latest,
            ];
        });

        return response()->json([
            'stats' => [
                'active_countries' => $totalCountries,
                'results_today' => $todayResults,
                'last_update' => Carbon::now()->toISOString(),
            ],
            'per_country' => $perCountry,
            'recent_results' => $recentResults,
        ]);
    }
}
