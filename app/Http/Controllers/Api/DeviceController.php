<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string',
            'device_platform' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $user->update([
            'fcm_token' => $validated['fcm_token'],
            'device_platform' => $validated['device_platform'] ?? $user->device_platform,
        ]);

        return response()->json(['message' => 'Dispositivo registrado exitosamente.']);
    }

    public function unregister(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['fcm_token' => null]);

        return response()->json(['message' => 'Dispositivo desregistrado exitosamente.']);
    }
}
