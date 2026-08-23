<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sources et journaux de scraping (agenda + cinéma), administrables. Remplace
// t_agenda_scrapping et formalise ce qui n'existait pas côté legacy pour le
// cinéma (l'auto-update Pathé-Gaumont n'avait ni config ni logs, voir
// TECHNICAL_DOCUMENTATION.md §3.3 de l'audit backend).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['agenda', 'cinema']);
            $table->string('driver_class'); // FQCN implémentant le contrat scraper du domaine
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable();
            $table->timestamps();
        });

        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('scraper_sources')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->enum('status', ['running', 'success', 'partial', 'failed'])->default('running');
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
        Schema::dropIfExists('scraper_sources');
    }
};
