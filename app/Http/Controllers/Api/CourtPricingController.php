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
        $rules = $court->pricingRules()->orderBy('day_of_week')->orderBy('start_time')->get();
        return response()->json([
            'success' => true,
            'pricing_rules' => $rules,
            'data' => [
                'court_id' => $court->id,
                'pricing_rules' => $rules,
            ]
        ]);
    }

    public function storeRule(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para gerenciar a precificação desta quadra.'
            ], 403);
        }

        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        // Validação de sobreposição de horários no mesmo dia da semana
        $existsOverlap = $court->pricingRules()
            ->where('day_of_week', $validated['day_of_week'])
            ->where(function ($q) use ($validated) {
                $q->where('start_time', '<', $validated['end_time'])
                  ->where('end_time', '>', $validated['start_time']);
            })
            ->exists();

        if ($existsOverlap) {
            return response()->json([
                'success' => false,
                'message' => 'Já existe uma tarifa de horário nobre cadastrada para este dia da semana que se sobrepõe ao horário informado.'
            ], 422);
        }

        $rule = $court->pricingRules()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Regra de horário nobre adicionada com sucesso',
            'data' => $rule,
            'pricing_rule' => $rule,
        ], 201);
    }

    public function destroyRule(Request $request, CourtPricingRule $pricingRule): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($pricingRule->court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para remover esta regra.'
            ], 403);
        }

        $pricingRule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Regra de preço removida com sucesso',
        ]);
    }

    // --- Special Prices (Feriados / Datas Especiais) ---

    public function indexSpecialPrices(Court $court): JsonResponse
    {
        $specials = $court->specialPrices()->orderBy('event_date')->get();
        return response()->json([
            'success' => true,
            'special_prices' => $specials,
            'data' => [
                'court_id' => $court->id,
                'special_prices' => $specials,
            ]
        ]);
    }

    public function storeSpecialPrice(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para gerenciar a precificação desta quadra.'
            ], 403);
        }

        $validated = $request->validate([
            'event_date' => ['required', 'date_format:Y-m-d'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        // Validação de duplicidade de data especial / feriado (compara só a data)
        $existsDate = $court->specialPrices()
            ->whereDate('event_date', $validated['event_date'])
            ->exists();

        if ($existsDate) {
            return response()->json([
                'success' => false,
                'message' => 'Já existe uma tarifa de feriado cadastrada para esta data.'
            ], 422);
        }

        $specialPrice = $court->specialPrices()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Preço especial de data/feriado cadastrado com sucesso',
            'data' => $specialPrice,
            'special_price' => $specialPrice,
        ], 201);
    }

    public function destroySpecialPrice(Request $request, CourtSpecialPrice $specialPrice): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($specialPrice->court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para remover este preço especial.'
            ], 403);
        }

        $specialPrice->delete();

        return response()->json([
            'success' => true,
            'message' => 'Preço especial removido com sucesso',
        ]);
    }
}
