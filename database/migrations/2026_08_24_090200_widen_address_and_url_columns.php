<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_areas/t_article (Phase 5) : plusieurs champs texte
// legacy dépassent la limite par défaut de 255 caractères de Laravel
// (adresse listant plusieurs communes, URLs longues...). Élargis par
// prudence pour ne perdre aucune donnée réelle lors de l'import.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->change();
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->change();
            $table->string('reservation_url', 500)->nullable()->change();
            $table->string('click_collect_url', 500)->nullable()->change();
        });
        Schema::table('event_categories', function (Blueprint $table) {
            // Certaines couleurs legacy sont en rgba(...) plutôt qu'en hex
            $table->string('color', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
            $table->string('reservation_url')->nullable()->change();
            $table->string('click_collect_url')->nullable()->change();
        });
        Schema::table('event_categories', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->change();
        });
    }
};
