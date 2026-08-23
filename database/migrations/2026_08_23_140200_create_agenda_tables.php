<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agenda / événements, dont la catégorie "theatre" (le menu THÉÂTRE du brief
// est un filtre de ce module, pas une entité séparée). Remplace t_agendas/
// t_agenda_categories/t_agenda_cat. La FK area_id est correcte ici (bug
// legacy id_area->t_agendas corrigé). Voir TECHNICAL_DOCUMENTATION.md §9.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // ex: 'theatre', 'concerts', 'festivals'
            $table->string('color', 20)->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('price')->nullable();
            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable();
            $table->json('schedule')->nullable(); // horaires récurrents éventuels
            $table->string('booking_url')->nullable();
            $table->enum('status', ['draft', 'pending', 'published', 'expired', 'cancelled'])->default('draft');
            $table->enum('source', ['manual', 'scraped', 'user_submitted'])->default('manual');
            $table->string('external_ref')->nullable()->index(); // dédup scraper
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'start_date']);
        });

        Schema::create('event_category', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_id', 'event_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_category');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_categories');
    }
};
