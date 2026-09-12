<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md
 * §36) : liste des inscrits, alimentée par 3 sources — inscription
 * volontaire homepage, inscription automatique via le formulaire de contact
 * (demande explicite du client), et reprise de l'historique
 * `toulouseweb_old.t_contacts` (voir `newsletter:import-legacy-contacts`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            // 'active' / 'unsubscribed' — pas de suppression physique sur
            // désinscription (on garde la trace, comme ContactMessage et les
            // autres modèles du projet ; voir aussi SoftDeletes ailleurs).
            $table->string('status')->default('active');
            // D'où vient l'inscription : 'homepage', 'contact_form',
            // 'legacy_import', 'admin' — utile pour l'audit, jamais affiché
            // publiquement.
            $table->string('source')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            // Lien de désinscription à un clic (pas de compte/connexion pour
            // un abonné newsletter) — unique, généré à la création.
            $table->string('unsubscribe_token', 64)->unique();
            // t_contacts.id (voir newsletter:import-legacy-contacts) : évite
            // de réimporter deux fois la même ligne si la commande est rejouée.
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
