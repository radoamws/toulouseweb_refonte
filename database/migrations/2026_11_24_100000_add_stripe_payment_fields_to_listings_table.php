<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement Stripe + essai gratuit temporaire pour l'annuaire (demande client,
 * 24/11/2026, voir TECHNICAL_DOCUMENTATION.md §83) :
 *
 * - `premium_until` : jusqu'à quand une fiche profite des options payantes
 *   (photo, description, galerie, site web, réservation) sans avoir payé —
 *   fixé à la création (3 mois, `Listing::TRIAL_MONTHS`), quelle que soit la
 *   formule choisie. Volontairement NULL pour toutes les fiches déjà
 *   existantes à cette migration (aucun essai rétroactif) — seules les
 *   NOUVELLES fiches en bénéficient à partir de maintenant.
 * - `paid_at` : date du règlement effectif (webhook Stripe, ou action
 *   manuelle d'un admin passant `tier` à `paid`) — informatif, affiché en
 *   admin.
 * - `stripe_checkout_session_id` : dernière session Stripe Checkout créée
 *   pour cette fiche, pour réconcilier le webhook et pour le support.
 * - `trial_reminder_sent_at` : évite un rappel envoyé plusieurs fois (voir
 *   App\Console\Commands\RemindListingTrialExpiring).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('premium_until')->nullable()->after('tier');
            $table->timestamp('paid_at')->nullable()->after('premium_until');
            $table->string('stripe_checkout_session_id')->nullable()->after('paid_at');
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['premium_until', 'paid_at', 'stripe_checkout_session_id', 'trial_reminder_sent_at']);
        });
    }
};
