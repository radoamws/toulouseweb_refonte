<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dépôt public d'une proposition d'actualité (demande client, 09/09/2026 —
// voir NewsController::store() et TECHNICAL_DOCUMENTATION.md §28). Distinct
// de `news.email` (email de contact PUBLIC affiché sur la fiche pour un
// article-événement, ajouté le 03/09/2026, brief "informations pratiques")
// — `submitter_email` n'est JAMAIS affiché publiquement, sert uniquement à
// l'équipe pour recontacter la personne qui a proposé l'actualité.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->string('submitter_email')->nullable()->after('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn('submitter_email');
        });
    }
};
