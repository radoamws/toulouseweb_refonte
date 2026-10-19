<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrêts réels Tisséo (brief roadmap "contenu", 16/10/2026 — recherche
 * d'itinéraire avec correspondance, voir TransportController::journey()),
 * en complément de `transport_lines.stops` (simple JSON de noms, inchangé,
 * toujours utilisé par la recherche "trajets directs" historique). Un
 * `stop_id` GTFS par ligne (pas de regroupement par `parent_station`) :
 * suffisant pour une recherche de correspondance par NOM, pas besoin de la
 * hiérarchie complète des quais pour cet usage.
 *
 * Régénérée entièrement à chaque import (voir ImportTisseoSchedule), même
 * principe que `transport_lines` — pas d'historique à conserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->string('gtfs_stop_id')->unique();
            $table->string('name');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_stops');
    }
};
