<?php

namespace App\Filament\Concerns;

/**
 * Retour sur le listing après sauvegarde (demande client) — sur les pages
 * Create/Edit Filament, aucune des deux ne redirige vers l'index par
 * défaut : `CreateRecord::getRedirectUrl()` va vers la page `view` puis
 * `edit` si elles existent (jamais l'index tant qu'aucune n'est
 * enregistrée) ; `EditRecord::getRedirectUrl()` retourne `null` (reste sur
 * la même page). Utilisé sur TOUTES les pages Create/Edit de l'admin, sans
 * exception (voir chaque `Pages\Create*`/`Pages\Edit*`).
 *
 * L'indicateur de chargement pendant la sauvegarde, lui, est déjà natif à
 * Filament (état `wire:loading` sur le bouton d'action Livewire à chaque
 * clic) — rien à ajouter côté code pour ce point.
 */
trait RedirectsToIndexAfterSave
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
