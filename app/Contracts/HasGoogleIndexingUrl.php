<?php

namespace App\Contracts;

/**
 * Implémenté par tout modèle dont une sauvegarde/suppression doit déclencher
 * une demande d'indexation Google (voir App\Observers\GoogleIndexingObserver
 * et App\Services\Seo\GoogleIndexingService, TECHNICAL_DOCUMENTATION.md §20).
 */
interface HasGoogleIndexingUrl
{
    /** URL canonique publique de la fiche (indépendamment de sa visibilité actuelle — voir isPubliclyVisible()). */
    public function publicUrl(): string;

    /** Le contenu est-il ACTUELLEMENT visible publiquement (statut publié, pas expiré...) ? */
    public function isPubliclyVisible(): bool;
}
