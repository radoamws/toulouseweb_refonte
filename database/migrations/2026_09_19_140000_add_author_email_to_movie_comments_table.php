<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Formulaire public d'avis sur les films (demande client, 19/09/2026) —
// email requis à la soumission pour contact éventuel côté modération, jamais
// affiché publiquement (voir CinemaController::storeComment()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_comments', function (Blueprint $table) {
            $table->string('author_email')->nullable()->after('author_name');
        });
    }

    public function down(): void
    {
        Schema::table('movie_comments', function (Blueprint $table) {
            $table->dropColumn('author_email');
        });
    }
};
