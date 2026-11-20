<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scraper d'actualités locales (demande client, 20/11/2026 : "la homepage
 * [...] les news ne sont pas à jour" + "prioriser les news publiés
 * manuellement") — voir TECHNICAL_DOCUMENTATION.md et
 * App\Services\Scraping\News\LeJournalToulousainDriver.
 *
 * `source`/`external_ref` : exactement les mêmes colonnes et la même
 * convention que sur `events` (`create_agenda_tables`, 'manual'/'scraped'/
 * 'user_submitted', dédup par `external_ref`) — `News` n'avait jusqu'ici
 * aucun moyen de distinguer une actu scrapée d'une soumission publique
 * (`submitter_email`) ou d'une actu créée par un admin.
 *
 * Backfill : toutes les lignes existantes (créées avant ce champ) sont soit
 * un dépôt public validé (`submitter_email` renseigné -> 'user_submitted'),
 * soit créées depuis l'admin ou migrées du legacy (`source` par défaut déjà
 * 'manual', rien à faire) — jamais 'scraped', ce scraper n'ayant jamais
 * existé avant aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->enum('source', ['manual', 'scraped', 'user_submitted'])->default('manual')->after('legacy_id');
            $table->string('external_ref')->nullable()->index()->after('source'); // dédup scraper
        });

        DB::table('news')->whereNotNull('submitter_email')->update(['source' => 'user_submitted']);
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
