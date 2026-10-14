<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes de transport Tisséo (brief roadmap "contenu", 14/10/2026, demande
 * client) — résumé par ligne (premier/dernier départ, fréquence indicative,
 * arrêts desservis), PAS une réplique complète du flux GTFS (1,3M lignes
 * d'horaires sur une fenêtre glissante de 3-4 semaines — jugé hors de
 * proportion avec la valeur réelle pour un contenu statique, voir
 * ImportTisseoGtfs::handle()). Une seule table, régénérée entièrement à
 * chaque import (pas d'historique à conserver), d'où l'absence de clé
 * étrangère vers une table "arrêts" séparée — `stops` est un simple JSON
 * ordonné (noms d'arrêt d'un trajet représentatif), suffisant pour l'usage
 * (une page par ligne, pas de recherche par arrêt pour l'instant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_lines', function (Blueprint $table) {
            $table->id();
            $table->string('gtfs_route_id')->unique();
            $table->string('name'); // ex. "A", "T1", "13", "TELEO"
            $table->string('long_name')->nullable();
            $table->string('slug')->unique();
            // GTFS route_type : 0 tram, 1 métro, 3 bus, 6 téléphérique
            // (Téléo) — seuls types réellement présents dans le flux Tisséo.
            $table->string('type');
            $table->string('color', 6)->nullable();
            $table->json('stops');
            $table->time('first_departure')->nullable();
            $table->time('last_departure')->nullable();
            $table->unsignedSmallInteger('frequency_minutes')->nullable();
            $table->unsignedInteger('trip_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_lines');
    }
};
