<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tracking de clics générique, étendu par rapport au legacy t_stat_counter
// (voir TECHNICAL_DOCUMENTATION.md §2.3) : n'importe quel clic sur le site
// (bannière, catégorie, encadré, fiche ciné/film...) est enregistré ici via
// POST /track-click, avec IP hashée/referrer/user-agent en plus du legacy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_events', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50); // 'listing', 'category', 'slider', 'movie', 'event', ...
            $table->unsignedBigInteger('entity_id');
            $table->string('context', 100)->nullable(); // ex: placement 'homepage_carousel', sous-catégorie
            $table->string('url', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('click_events');
    }
};
