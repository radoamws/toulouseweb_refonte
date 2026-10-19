<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trajets réels Tisséo de la journée représentative (brief roadmap
 * "contenu", 16/10/2026, voir ImportTisseoSchedule). `gtfs_route_id` est
 * stocké tel quel (string), PAS une clé étrangère vers `transport_lines` :
 * les deux imports (`transport:import-tisseo-gtfs` et
 * `transport:import-tisseo-schedule`) sont déclenchés indépendamment, à des
 * horaires différents (voir docblock de ImportTisseoSchedule) — le lien
 * avec `transport_lines` se fait uniquement en LECTURE, au moment de la
 * recherche (TransportController::journey()).
 *
 * Régénérée entièrement à chaque import, même principe que
 * `transport_lines`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_trips', function (Blueprint $table) {
            $table->id();
            $table->string('gtfs_trip_id')->unique();
            $table->string('gtfs_route_id');
            $table->timestamps();

            $table->index('gtfs_route_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_trips');
    }
};
