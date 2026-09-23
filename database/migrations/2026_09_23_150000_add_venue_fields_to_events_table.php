<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adresse propre à l'événement (demande client, 23/09/2026) — distincte de
// l'adresse générique du `Area` associé : les agendas mutualisés
// (OpenAgenda, ex. "Toulouse Métropole") agrègent des événements qui se
// déroulent chacun à un lieu physique DIFFÉRENT, tous rattachés au même
// `area_id` générique. Voir App\Services\Scraping\Agenda\Concerns\AbstractOpenAgendaDriver.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('venue_name')->nullable()->after('area_id');
            $table->string('venue_address')->nullable()->after('venue_name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['venue_name', 'venue_address']);
        });
    }
};
