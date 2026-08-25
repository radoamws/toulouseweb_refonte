<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (performance, brief §12) : deux index manquants identifiés par
 * audit des requêtes réelles des contrôleurs/widgets (25/08/2026), voir
 * TECHNICAL_DOCUMENTATION.md §13 :
 *
 * - `listings.city` : filtré par ListingController::renderIndex() (?city=,
 *   recherche géographique) sans index.
 * - `click_events.created_at` (+entity_type/entity_id en couverture) :
 *   ClickTrackingService::totalCount()/totalsByType()/topEntities() filtrent
 *   TOUS par plage `created_at` SANS filtrer `entity_type` — l'index
 *   composite existant `(entity_type, entity_id, created_at)` ne peut pas
 *   servir ces requêtes (created_at n'est pas en tête). Avec ~2,78M lignes
 *   (la plus grosse table de la base, historique de clics migré), ces 3
 *   requêtes tournent en full scan à CHAQUE chargement du dashboard admin
 *   (widgets non lazy) — un vrai goulot d'étranglement, pas une
 *   optimisation prématurée. Le nouvel index sert aussi de "covering index"
 *   pour les GROUP BY entity_type/entity_id de `totalsByType()`/
 *   `topEntities()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->index('city');
        });

        Schema::table('click_events', function (Blueprint $table) {
            $table->index(['created_at', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['city']);
        });

        Schema::table('click_events', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'entity_type', 'entity_id']);
        });
    }
};
