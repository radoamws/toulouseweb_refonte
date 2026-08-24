<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// t_contact_sites (sites partenaires, brief page contact) n'avait jamais été
// migré (MigratePartnerSites, ajoutée avec cette migration) — la table
// partner_sites existait déjà (scaffold) mais sans legacy_id pour un import
// idempotent, voir TECHNICAL_DOCUMENTATION.md §13.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_sites', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('partner_sites', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });
    }
};
