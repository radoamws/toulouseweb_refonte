<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_cine_projection (Phase 5) : le legacy a bien
// plusieurs lignes de projection pour un même triplet salle/film/langue,
// distinguées par leur fenêtre de validité (start_date/end_date) — la
// contrainte unique posée dans le schéma initial était trop stricte.
return new class extends Migration
{
    public function up(): void
    {
        // L'index de remplacement doit exister AVANT de supprimer l'unique
        // (les FK cinema_id/movie_id/language_id ont besoin d'un index).
        Schema::table('screenings', function (Blueprint $table) {
            $table->index(['cinema_id', 'movie_id', 'language_id'], 'screenings_cinema_movie_language_index');
        });
        Schema::table('screenings', function (Blueprint $table) {
            $table->dropUnique('screenings_cinema_id_movie_id_language_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('screenings', function (Blueprint $table) {
            $table->dropIndex(['cinema_id', 'movie_id', 'language_id']);
            $table->unique(['cinema_id', 'movie_id', 'language_id']);
        });
    }
};
