<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demande client (03/09/2026) : une actualité peut décrire un vrai
 * événement (salon, brocante, animation...) et a besoin de ses propres
 * informations pratiques, distinctes de `published_at` (date de mise en
 * ligne de l'ARTICLE, pas de l'événement qu'il décrit).
 *
 * `start_date`/`end_date` sont des colonnes `date` PURES (pas `datetime`) —
 * demande explicite du client ("juste les dates et non l'heure"). Voir
 * `CinemaController::currentlyValid()` (TECHNICAL_DOCUMENTATION.md, bug du
 * 01/09/2026) pour le piège à éviter : comparer cette colonne à une chaîne
 * `now()->toDateString()` nue, jamais à un `now()`/objet `Carbon` complet.
 *
 * `schedule` est un texte libre ("Tous les jours de 10h à 18h, sauf le
 * lundi...") — à ne pas confondre avec `events.schedule`, qui est un JSON
 * structuré ; même nom de colonne, sémantique différente d'un modèle à
 * l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('excerpt');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('schedule', 500)->nullable()->after('end_date'); // horaire, texte libre
            $table->string('address')->nullable()->after('schedule');
            $table->string('price')->nullable()->after('address'); // texte libre ("Gratuit", "5€ - 12€"...)
            $table->string('phone', 30)->nullable()->after('price');
            $table->string('email')->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
            $table->string('youtube_url')->nullable()->after('website');

            $table->index(['status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropIndex(['status', 'end_date']);
            $table->dropColumn([
                'start_date', 'end_date', 'schedule', 'address', 'price', 'phone', 'email', 'website', 'youtube_url',
            ]);
        });
    }
};
