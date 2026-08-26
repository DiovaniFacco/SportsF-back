<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VenueCoverUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'type' => ['nullable', 'string', 'in:venue,court'],
        ]);

        $folder = ($validated['type'] ?? 'venue') === 'court' ? 'court-covers' : 'venue-covers';

        $file = $request->file('image');

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $filename = Str::uuid()->toString() . '.' . $extension;

        $path = $file->storeAs($folder, $filename, 'public');

        return response()->json([
            'message' => 'Imagem enviada com sucesso',
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }
}
