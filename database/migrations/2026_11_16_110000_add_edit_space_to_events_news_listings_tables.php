<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace client, étapes 2 et 3 (16/11/2026) — voir
 * 2026_11_16_100000_add_edit_space_to_classifieds_table.php (étape 1,
 * annonces) pour le détail de l'architecture, identique ici.
 *
 * `events`/`news` avaient déjà `submitter_email` (collecté par leurs
 * formulaires publics respectifs depuis le 12/11/2026) — seuls `edit_token`
 * et `pending_changes` manquent ici.
 *
 * `listings` n'avait ni l'un ni l'autre : `email` y est un champ métier
 * public (contact de l'établissement), jamais l'email du déposant. Un
 * `submitter_email` dédié est ajouté, mais seules les fiches créées à partir
 * de maintenant en seront pourvues — les ~2978 fiches historiques migrées du
 * legacy n'ont aucun email de déposant fiable à exploiter (champ libre non
 * structuré, voir docblock de ListingController) et resteront sans accès
 * espace client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('edit_token')->nullable()->unique()->after('submitter_email');
            $table->json('pending_changes')->nullable()->after('edit_token');
        });

        Schema::table('news', function (Blueprint $table) {
            $table->string('edit_token')->nullable()->unique()->after('submitter_email');
            $table->json('pending_changes')->nullable()->after('edit_token');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->string('submitter_email')->nullable()->after('email');
            $table->string('edit_token')->nullable()->unique()->after('submitter_email');
            $table->json('pending_changes')->nullable()->after('edit_token');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['edit_token', 'pending_changes']);
        });

        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn(['edit_token', 'pending_changes']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['submitter_email', 'edit_token', 'pending_changes']);
        });
    }
};
