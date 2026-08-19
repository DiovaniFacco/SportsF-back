<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtPricingRule;
use App\Models\CourtSpecialPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtPricingController extends Controller
{
    // --- Pricing Rules (Horário Nobre) ---

    public function indexRules(Court $court): JsonResponse
    {
        return response()->json([
            'court_id' => $court->id,
            'pricing_rules' => $court->pricingRules()->orderBy('day_of_week')->orderBy('start_time')->get(),
        ]);
    }

    public function storeRule(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar a precificação desta quadra.'], 403);
        }

        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $rule = $court->pricingRules()->create($validated);

        return response()->json([
            'message' => 'Regra de horário nobre adicionada com sucesso',
            'pricing_rule' => $rule,
        ], 201);
    }

    public function destroyRule(Request $request, CourtPricingRule $pricingRule): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $pricingRule->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para remover esta regra.'], 403);
        }

        $pricingRule->delete();

        return response()->json([
            'message' => 'Regra de preço removida com sucesso',
        ]);
    }

    // --- Special Prices (Feriados / Datas Especiais) ---

    public function indexSpecialPrices(Court $court): JsonResponse
    {
        return response()->json([
            'court_id' => $court->id,
            'special_prices' => $court->specialPrices()->orderBy('event_date')->get(),
        ]);
    }

    public function storeSpecialPrice(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar a precificação desta quadra.'], 403);
        }

        $validated = $request->validate([
            'event_date' => ['required', 'date_format:Y-m-d'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $specialPrice = $court->specialPrices()->create($validated);

        return response()->json([
            'message' => 'Preço especial de data/feriado cadastrado com sucesso',
            'special_price' => $specialPrice,
        ], 201);
    }

    public function destroySpecialPrice(Request $request, CourtSpecialPrice $specialPrice): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $specialPrice->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para remover este preço especial.'], 403);
        }

        $specialPrice->delete();

        return response()->json([
            'message' => 'Preço especial removido com sucesso',
        ]);
    }
}
