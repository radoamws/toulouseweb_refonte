<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md
 * §36) : une ligne = une campagne (créée à la main dans l'admin, ou
 * générée en brouillon automatiquement à la publication d'une fiche
 * annuaire/actualité/annonce ou après un scraping agenda/cinéma — voir
 * App\Observers\NewsletterDraftObserver et
 * App\Console\Commands\Migration\... pour les déclencheurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('preview_text')->nullable();
            $table->longText('body_html');
            // 'draft' / 'sent' — pas d'état "en cours d'envoi" séparé, un
            // envoi complet se fait en une seule mise en file (voir
            // App\Services\Newsletter\NewsletterSender).
            $table->string('status')->default('draft');
            // 'manual' (créée à la main dans l'admin), 'listing_published',
            // 'news_published', 'classified_published', 'scraping_digest'
            // (agenda/cinéma) — traçabilité, jamais affiché publiquement.
            $table->string('trigger_type')->nullable();
            // Fiche à l'origine du brouillon auto-généré (Listing/News/
            // Classified) — null pour une newsletter manuelle ou un digest
            // de scraping (qui résume plusieurs fiches, aucune en particulier).
            $table->nullableMorphs('triggerable');
            $table->timestamp('test_sent_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletters');
    }
};
