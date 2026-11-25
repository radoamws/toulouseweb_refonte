<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rectification client (25/11/2026) : la fiche payante n'est plus acquise
 * "pour toujours" mais pour 1 an (`premium_until` prolongé de 1 an à chaque
 * paiement, voir StripePaymentService::markListingAsPaid()) — voir
 * TECHNICAL_DOCUMENTATION.md §83.
 *
 * - `renewal_reminder_sent_at` : rappel de renouvellement envoyé aux fiches
 *   PAYANTES dont l'échéance approche (distinct de `trial_reminder_sent_at`,
 *   qui ne concerne que l'essai gratuit — voir
 *   App\Console\Commands\RemindListingTrialExpiring) ; remis à `null` à
 *   chaque renouvellement effectif pour que le rappel puisse se redéclencher
 *   l'année suivante.
 * - `stripe_last_processed_session_id` : id de la dernière session Checkout
 *   réellement appliquée par le webhook — Stripe peut renvoyer le même
 *   évènement plusieurs fois ; sans cet id distinct de
 *   `stripe_checkout_session_id` (qui, lui, est écrasé dès la CRÉATION de la
 *   session, avant même le paiement), un renvoi du même webhook prolongerait
 *   `premium_until` une deuxième fois à tort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('renewal_reminder_sent_at')->nullable()->after('trial_reminder_sent_at');
            $table->string('stripe_last_processed_session_id')->nullable()->after('renewal_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminder_sent_at', 'stripe_last_processed_session_id']);
        });
    }
};
