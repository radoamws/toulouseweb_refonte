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

    /** Nombre total de clics, toutes entités confondues, sur une plage de dates (bornes incluses). */
    public function totalCount(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return ClickEvent::query()->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Nombre de clics par type d'entité sur une plage de dates — alimente le
     * graphique "clics par type" du dashboard admin (brief : statistiques
     * étendues à chaque clic du site, quel que soit son type).
     *
     * @return array<string, int> entity_type => total
     */
    public function totalsByType(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ClickEvent::query()
            ->selectRaw('entity_type, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('entity_type')
            ->orderByDesc('total')
            ->pluck('total', 'entity_type')
            ->all();
    }

    /**
     * Les N entités (tous types confondus) les plus cliquées sur une plage
     * de dates — alimente le widget "Top clics" du dashboard admin.
     *
     * @return array<int, array{entity_type: string, entity_id: int, total: int}>
     */
    public function topEntities(int $limit, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ClickEvent::query()
            ->selectRaw('entity_type, entity_id, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('entity_type', 'entity_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['entity_type' => $row->entity_type, 'entity_id' => (int) $row->entity_id, 'total' => (int) $row->total])
            ->all();
    }
}
