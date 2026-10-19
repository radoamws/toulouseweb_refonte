<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horaires par arrêt de la journée représentative (brief roadmap "contenu",
 * 16/10/2026, voir ImportTisseoSchedule) — ~236 000 lignes mesurées en réel
 * le 16/10/2026 pour un mercredi type (~10 800 trajets), table la plus
 * volumineuse du projet. Pas de `timestamps()` : régénérée entièrement à
 * chaque import (même principe que `transport_lines`), `created_at` serait
 * identique sur toutes les lignes et n'apporterait aucune information.
 *
 * `departure_time` en VARCHAR (pas `time()`) : convention GTFS standard où
 * l'heure peut dépasser 24h pour un trajet après minuit (ex. "24:40:00"),
 * même raisonnement que `TransportLine::formatTime()`.
 *
 * Index sur les 2 colonnes de jointure utilisées par
 * TransportController::journey() (auto-indexées par `constrained()`) :
 * recherche de trajet direct (self-jointure sur `transport_trip_id`) et
 * résolution des stop_id par nom (jointure sur `transport_stop_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_stop_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_stop_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('stop_sequence');
            $table->string('departure_time', 8);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_stop_times');
    }
};
