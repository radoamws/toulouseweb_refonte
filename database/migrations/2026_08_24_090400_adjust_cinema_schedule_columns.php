<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_cine_projection/t_cine_proj_heures (Phase 5) : le
// legacy ne stocke pas des séances à date fixe mais un gabarit hebdomadaire
// récurrent — `t_cine_projection` porte une fenêtre de validité
// (start_date/end_date) et `t_cine_proj_heures.jour` est un INDEX DE JOUR DE
// SEMAINE (0-6), pas une date calendaire. Le schéma initial avait modélisé
// `screening_times.day` comme une date — corrigé ici pour refléter fidèlement
// la donnée source (voir TECHNICAL_DOCUMENTATION.md §10).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('language_id');
            $table->date('end_date')->nullable()->after('start_date');
        });

        Schema::table('screening_times', function (Blueprint $table) {
            $table->dropColumn('day');
        });
        Schema::table('screening_times', function (Blueprint $table) {
            // 0-6, convention identique à la donnée source legacy (`jour`)
            $table->unsignedTinyInteger('weekday')->after('screening_id');
            $table->index(['weekday', 'time']);
        });
    }

    public function down(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });

        Schema::table('screening_times', function (Blueprint $table) {
            $table->dropIndex(['weekday', 'time']);
        });
        Schema::table('screening_times', function (Blueprint $table) {
            $table->dropColumn('weekday');
        });
        Schema::table('screening_times', function (Blueprint $table) {
            $table->date('day')->after('screening_id');
        });
    }
};
