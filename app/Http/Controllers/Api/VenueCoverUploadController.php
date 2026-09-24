<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Cloudinary\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        // Tenta Cloudinary primeiro (prod). Se não estiver configurado,
        // faz fallback para disco public local (dev) em vez de estourar 500.
        if ($this->isCloudinaryConfigured()) {
            try {
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
                    throw new \RuntimeException('Cloudinary não retornou secure_url.');
                }

                return response()->json([
                    'message' => 'Imagem enviada com sucesso',
                    'url'     => $url,
                ], 201);
            } catch (\Throwable $e) {
                Log::error('Falha no upload para Cloudinary, usando fallback local.', [
                    'error' => $e->getMessage(),
                ]);
                // cai para o fallback local abaixo
            }
        } else {
            Log::warning('Cloudinary não configurado (CLOUDINARY_URL ausente). Usando storage local.');
        }

        // Fallback: storage local no disco "public".
        // Usa o host da requisição para nunca gerar link localhost em prod
        // mesmo se APP_URL estiver desatualizado na Railway.
        try {
            $localFolder = $type === 'court' ? 'court-covers' : 'venue-covers';
            $path = $file->store($localFolder, 'public');

            if (! $path) {
                return response()->json(['message' => 'Erro ao salvar imagem no storage local.'], 500);
            }

            $url = $request->getSchemeAndHttpHost().'/storage/'.$path;

            return response()->json([
                'message' => 'Imagem enviada com sucesso',
                'url'     => $url,
                'storage' => 'local',
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Falha no upload de imagem (fallback local).', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erro ao processar upload da imagem.',
                ...(config('app.debug') ? ['error' => $e->getMessage()] : []),
            ], 500);
        }
    }

    private function isCloudinaryConfigured(): bool
    {
        $cloudUrl = (string) config('cloudinary.cloud_url');

        // Config válida via CLOUDINARY_URL completa
        if ($cloudUrl !== '' && $cloudUrl !== 'cloudinary://:@' && str_contains($cloudUrl, '@')) {
            return true;
        }

        // Ou via credenciais individuais
        return (bool) (config('cloudinary.cloud_name') && config('cloudinary.api_key') && config('cloudinary.api_secret'));
    }
}
