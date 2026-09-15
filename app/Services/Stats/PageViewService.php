<?php

namespace App\Services\Stats;

use App\Models\PageView;
use Illuminate\Http\Request;

/**
 * Enregistre une VUE de page (demande client, 15/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §44) — voir docblock d'App\Models\PageView
 * pour la différence avec App\Services\Stats\ClickTrackingService (clic
 * suivi côté client vs vue enregistrée côté serveur, à chaque affichage,
 * quel que soit le point d'entrée : recherche Google, lien partagé,
 * navigation interne...).
 *
 * Appelé directement depuis les contrôleurs publics (`HomeController`,
 * `ListingController::show()`...), jamais depuis une route dédiée — un
 * enregistrement de vue accompagne TOUJOURS un rendu de page réel, pas un
 * appel HTTP séparé comme /track-click (qui, lui, dépend du JS et peut être
 * bloqué par un bloqueur de scripts/pub — la vue serveur ne dépend d'aucun
 * JavaScript côté visiteur).
 */
class PageViewService
{
    /** @param ?string $entityType Null pour une page sans entité précise (home, page d'accueil d'une liste...). */
    public function record(Request $request, string $path, ?string $entityType = null, ?int $entityId = null): void
    {
        PageView::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'path' => $path,
            'referrer' => $request->headers->get('referer'),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'session_hash' => $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
            'created_at' => now(),
        ]);
    }

    /** Nombre total de vues, toutes pages confondues, sur une plage de dates (bornes incluses). */
    public function totalCount(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return PageView::query()->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Nombre de vues par type d'entité sur une plage de dates — alimente le
     * graphique "Vues par type" du dashboard admin. Les vues sans entité
     * (home, etc.) apparaissent sous la clé 'page' plutôt que d'être
     * silencieusement exclues.
     *
     * @return array<string, int> entity_type => total
     */
    public function totalsByType(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return PageView::query()
            ->selectRaw("COALESCE(entity_type, 'page') as entity_type, COUNT(*) as total")
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('entity_type')
            ->orderByDesc('total')
            ->pluck('total', 'entity_type')
            ->all();
    }

    /**
     * Les N entités (tous types confondus) les plus vues sur une plage de
     * dates — alimente le widget "Pages les plus vues" du dashboard admin.
     * Exclut les vues sans entité (`entity_type IS NULL`, ex. home) : sans
     * identifiant, impossible de les grouper/libeller individuellement —
     * elles restent comptées dans `totalCount()`/`totalsByType()`.
     *
     * @return array<int, array{entity_type: string, entity_id: int, total: int}>
     */
    public function topEntities(int $limit, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return PageView::query()
            ->whereNotNull('entity_type')
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
