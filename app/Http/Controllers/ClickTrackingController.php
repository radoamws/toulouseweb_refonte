<?php

namespace App\Http\Controllers;

use App\Services\Stats\ClickTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Point d'entrée unique de tracking de clics, appelable depuis n'importe
 * quel composant Blade public (data-track="listing:123:homepage_card").
 * Voir resources/js/track-click.js et TECHNICAL_DOCUMENTATION.md §9.
 */
class ClickTrackingController extends Controller
{
    public function __invoke(Request $request, ClickTrackingService $tracking): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:50'],
            'entity_id' => ['required', 'integer'],
            'context' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'string', 'max:500'],
        ]);

        $tracking->record(
            $request,
            $validated['entity_type'],
            $validated['entity_id'],
            $validated['context'] ?? null,
        );

        return response()->json(['status' => 'ok']);
    }
}
