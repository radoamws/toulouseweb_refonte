<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demande client (04/09/2026, /admin/news/create) : "ne pas limiter la
 * longueur du texte car il y a des liens très long" — mêmes URLs de
 * billetterie/tracking à rallonge déjà rencontrées sur `events.booking_url`/
 * `screening_times.booking_url` (voir `widen_url_columns_phase5`/
 * `widen_screening_time_booking_url`), ici sur les nouveaux champs
 * `website`/`youtube_url` de `news` (§ "informations pratiques", 03/09/2026).
 * `text()` plutôt qu'un `string()` élargi : supprime la limite au lieu de
 * simplement la reculer, conformément à la demande explicite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->text('website')->nullable()->change();
            $table->text('youtube_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->string('website')->nullable()->change();
            $table->string('youtube_url')->nullable()->change();
        });
    }
};
