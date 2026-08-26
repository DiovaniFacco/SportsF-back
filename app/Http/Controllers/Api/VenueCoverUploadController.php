<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Cloudinary\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueCoverUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'type'  => ['nullable', 'string', 'in:venue,court'],
        ]);

        $type   = $validated['type'] ?? 'venue';
        $folder = $type === 'court' ? 'sports-app/court-covers' : 'sports-app/venue-covers';

        $file = $request->file('image');

        // Inicializa via CLOUDINARY_URL para evitar problemas de assinatura
        $cloudinary = new Cloudinary(config('cloudinary.cloud_url'));

        $result = $cloudinary->uploadApi()->upload(
            $file->getRealPath(),
            [
                'folder'        => $folder,
                'resource_type' => 'image',
            ]
        );

        $url = $result['secure_url'] ?? null;

        if (! $url) {
            return response()->json(['message' => 'Erro ao obter URL da imagem no Cloudinary.'], 500);
        }

        return response()->json([
            'message' => 'Imagem enviada com sucesso',
            'url'     => $url,
        ], 201);
    }
}
