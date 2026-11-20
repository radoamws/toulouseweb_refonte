<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace client annonces (demande client, 16/11/2026, suite à la
 * réclamation de Mme Alves de Sousa — "je souhaite modifier [mon annonce]
 * mais je ne veux pas que mes coordonnées apparaissent de cette manière").
 * Voir TECHNICAL_DOCUMENTATION.md pour le détail de l'architecture.
 *
 * `edit_token` suit exactement le pattern déjà en place pour
 * `NewsletterSubscriber::unsubscribe_token` (token opaque en colonne,
 * `Str::random(48)`, lookup direct) — pas de nouvelle table de tokens.
 *
 * `submitter_email` est VOLONTAIREMENT distinct de `contact_email` : ce
 * dernier est affiché publiquement sur la fiche et peut être modifié par le
 * déposant lui-même (c'est justement l'objet de la réclamation) — si
 * `edit_token` avait été dérivé de `contact_email`, le déposant aurait pu se
 * couper l'accès à son propre espace en le changeant. `submitter_email` est
 * capturé une seule fois à la création et n'est plus jamais modifié ensuite.
 *
 * `pending_changes` (JSON) porte une modification proposée par le déposant
 * SANS jamais toucher aux colonnes publiées : une annonce déjà en ligne
 * reste affichée telle quelle (status inchangé) tant que l'équipe n'a pas
 * validé la modification depuis l'admin — contrairement à `status=pending`
 * (qui masquerait une annonce déjà publiée le temps de la relecture, un
 * effet de bord inacceptable pour une simple modification).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->string('submitter_email')->nullable()->after('contact_email');
            $table->string('edit_token')->nullable()->unique()->after('submitter_email');
            $table->json('pending_changes')->nullable()->after('edit_token');
        });
    }

    public function down(): void
    {
        Schema::table('classifieds', function (Blueprint $table) {
            $table->dropColumn(['submitter_email', 'edit_token', 'pending_changes']);
        });
    }
};
