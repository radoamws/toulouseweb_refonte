<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Découvert en migrant t_areas : certains champs "téléphone" legacy
// contiennent en réalité du texte libre (ex: "05 61 21 20 46 (admin. +
// répondeur prog.).") dépassant largement un numéro de téléphone normal.
// On élargit par prudence tous les champs téléphone du schéma plutôt que
// de tronquer/perdre l'information lors de la migration (Phase 5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->change();
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->change();
        });
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->change();
        });
        Schema::table('classifieds', function (Blueprint $table) {
            $table->string('contact_phone', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
        Schema::table('classifieds', function (Blueprint $table) {
            $table->string('contact_phone', 30)->nullable()->change();
        });
    }
};
