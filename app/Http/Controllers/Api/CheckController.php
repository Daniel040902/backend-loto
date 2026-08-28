<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CheckController extends Controller
{
    public function check(): JsonResponse
    {
        $dbOk = false;
        try {
            \DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'database' => $dbOk ? 'connected' : 'disconnected',
            'app_name' => config('app.name'),
            'environment' => config('app.env'),
        ]);
    }
}
