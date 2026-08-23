<?php

namespace App\Services\Stats;

use App\Models\ClickEvent;
use Illuminate\Http\Request;

/**
 * Enregistre un clic pour n'importe quelle entité du site (bannière,
 * catégorie, encadré/fiche, film, événement...). Remplace/étend le
 * t_stat_counter legacy en ajoutant referrer/user-agent/IP hashée — voir
 * TECHNICAL_DOCUMENTATION.md §2.3 et la demande client de statistiques
 * étendues à chaque clic du site.
 */
class ClickTrackingService
{
    public function record(Request $request, string $entityType, int $entityId, ?string $context = null): ClickEvent
    {
        return ClickEvent::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'context' => $context,
            'url' => (string) $request->input('url', $request->headers->get('referer')),
            'referrer' => $request->headers->get('referer'),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'session_hash' => $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Résumé du nombre de clics par jour sur une plage de dates, pour un
     * type d'entité donné — alimente les widgets du dashboard Filament
     * (équivalent de StatController::getStatResumeEntite côté legacy).
     */
    public function dailySummary(string $entityType, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ClickEvent::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('entity_type', $entityType)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->all();
    }
}
