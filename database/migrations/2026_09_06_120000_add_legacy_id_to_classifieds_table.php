<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `classifieds` avait été délibérément créée SANS `legacy_id` (voir
 * commentaire de `create_classifieds_tables.php` : "t_annonce... quasi
 * inutilisées en legacy, non migrées telles quelles") — décision revue lors
 * du cutover prod du 06/09/2026 (TECHNICAL_DOCUMENTATION.md §22) : le volume
 * réel est faible (3 lignes) mais existe et doit être migré comme les autres
 * entités, avec le même mécanisme d'upsert idempotent (`legacy_id`) que
 * partout ailleurs — voir MigrateClassifieds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });
    }
};
