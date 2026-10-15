<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccessTrackingController extends Controller
{
    /**
     * Endpoint público de coleta de acessos.
     *
     * O token Bearer é OPCIONAL: quando presente e válido, o acesso é
     * atribuído ao usuário logado (user_id + role). Sem token, o evento é
     * tratado como visitante anônimo (user_id NULL) — que é a métrica que
     * queremos medir (quantos entram sem estar logados).
     *
     * Nunca falha de forma ruidosa: telemetria é best-effort.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['nullable', 'string', 'in:app_open,page_view'],
            'screen' => ['nullable', 'string', 'max:64'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'platform' => ['nullable', 'string', 'max:20'],
            'referrer' => ['nullable', 'string', 'max:512'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = Auth::guard('sanctum')->user();

        AccessEvent::create([
            'venue_id' => $validated['venue_id'] ?? null,
            'user_id' => $user?->id,
            'user_role' => $user?->role,
            'visitor_id' => $validated['visitor_id'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'event_type' => $validated['event_type'] ?? AccessEvent::TYPE_PAGE_VIEW,
            'screen' => $validated['screen'] ?? null,
            'platform' => $this->resolvePlatform($request, $validated['platform'] ?? null),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'referrer' => $validated['referrer'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json(['status' => 'ok'], 201);
    }

    /**
     * Normaliza a plataforma: usa o valor enviado pelo cliente, o header
     * X-Client-Platform ou, como fallback, infere pelo User-Agent.
     */
    private function resolvePlatform(Request $request, ?string $provided): string
    {
        $candidate = strtolower(trim((string) ($provided ?: $request->header('X-Client-Platform'))));
        if (in_array($candidate, ['web', 'android', 'ios'], true)) {
            return $candidate;
        }

        $ua = strtolower((string) $request->userAgent());
        if (str_contains($ua, 'android')) {
            return 'android';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            return 'ios';
        }

        return 'web';
    }
}
