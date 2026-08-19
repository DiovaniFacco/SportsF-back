<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use Illuminate\Http\JsonResponse;

class SportController extends Controller
{
    public function index(): JsonResponse
    {
        $sports = Sport::withCount('courts')->get();

        return response()->json([
            'sports' => $sports,
        ]);
    }
}
