<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vue de page (demande client, 15/09/2026, voir
 * App\Services\Stats\PageViewService et TECHNICAL_DOCUMENTATION.md §44) —
 * enregistrée côté SERVEUR à chaque affichage d'une page publique, contrairement
 * à App\Models\ClickEvent (enregistré côté client, seulement sur un clic
 * suivi) : capte aussi les visites directes (résultat de recherche, lien
 * partagé, favori...), pas seulement la navigation interne.
 */
class PageView extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'entity_type', 'entity_id', 'path', 'referrer', 'user_agent',
        'ip_hash', 'session_hash', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
