<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    public function index(): JsonResponse
    {
        $countries = Country::active()->with(['games' => function ($q) {
            $q->where('active', true)->orderBy('sort_order');
        }])->get();

        return response()->json($countries);
    }

    public function show(string $slug): JsonResponse
    {
        $country = Country::where('slug', $slug)
            ->with(['games' => function ($q) {
                $q->where('active', true)->orderBy('sort_order');
            }])
            ->firstOrFail();

        return response()->json($country);
    }
}
