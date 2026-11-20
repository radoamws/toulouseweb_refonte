<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache chaque publication à un compte éditeur (nullable : jamais
 * obligatoire, un dépôt anonyme reste possible — voir
 * TECHNICAL_DOCUMENTATION.md).
 *
 * ⚠️ Volontairement une colonne `editor_id` NOUVELLE, pas une réutilisation
 * de `classifieds.user_id` (existant, référence `users`) : ce dernier est
 * documenté "legacy only, jamais renseigné par le contrôleur public" (voir
 * App\Models\Classified) — changer sa cible aurait mélangé deux concepts
 * différents (auteur STAFF historique vs compte éditeur PUBLIC).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->foreignId('editor_id')->nullable()->after('user_id')->constrained('editors')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('editor_id')->nullable()->after('submitter_email')->constrained('editors')->nullOnDelete();
        });

        Schema::table('news', function (Blueprint $table) {
            $table->foreignId('editor_id')->nullable()->after('submitter_email')->constrained('editors')->nullOnDelete();
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('editor_id')->nullable()->after('submitter_email')->constrained('editors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_id');
        });

        Schema::table('news', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_id');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_id');
        });
    }
};
