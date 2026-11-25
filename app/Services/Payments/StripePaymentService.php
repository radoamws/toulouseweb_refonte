<?php

namespace App\Services\Payments;

use App\Models\Listing;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Paiement Stripe pour les fiches annuaire payantes (demande client,
 * 24/11/2026, rectifiée en abonnement annuel le 25/11/2026, voir
 * TECHNICAL_DOCUMENTATION.md §83). Checkout hébergé par Stripe (pas de
 * formulaire de carte bancaire sur ce site, aucune donnée de paiement ne
 * transite ni n'est stockée ici) — un paiement unique à chaque échéance
 * (`mode: 'payment'`, PAS un abonnement Stripe récurrent : aucune carte
 * enregistrée, le client clique chaque année sur le lien reçu par email de
 * rappel ou dans son espace de gestion, voir docblock de
 * ListingPaymentController pour le raisonnement produit).
 *
 * Classe concrète (pas d'interface) volontairement : les tests remplacent
 * cette classe via `$this->mock(StripePaymentService::class, ...)`, qui
 * fonctionne par résolution du conteneur sans avoir besoin d'une interface
 * séparée (voir tests/Feature/ListingPaymentTest.php).
 */
class StripePaymentService
{
    protected function client(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Crée une session Stripe Checkout pour une fiche et renvoie son URL de
     * redirection. Le montant vient de `STRIPE_LISTING_PRICE_CENTS` (100€
     * par défaut, voir config/services.php) plutôt que d'être codé en dur
     * ici, pour rester modifiable sans déploiement si le tarif change.
     * `metadata.listing_id` permet au webhook de retrouver la fiche sans
     * dépendre de l'ordre d'arrivée des évènements.
     */
    public function createCheckoutSessionUrl(Listing $listing, string $successUrl, string $cancelUrl): string
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => "Fiche annuaire payante — {$listing->title} (1 an)",
                        'description' => 'ToulouseWeb — référencement complet de la fiche (photo, description, site web, réservation) + mise en avant dans les résultats de recherche, pour 1 an.',
                    ],
                    'unit_amount' => config('services.stripe.listing_price_cents'),
                ],
                'quantity' => 1,
            ]],
            'customer_email' => $listing->submitter_email ?: $listing->email,
            'metadata' => ['listing_id' => (string) $listing->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $listing->stripe_checkout_session_id = $session->id;
        $listing->save();

        return $session->url;
    }

    /**
     * Vérifie la signature HMAC du payload webhook (en-tête Stripe-Signature
     * contre STRIPE_WEBHOOK_SECRET) — seule authentification de cette route
     * (exemptée de CSRF, voir bootstrap/app.php). Lève une exception Stripe
     * si la signature est invalide ; le contrôleur appelant répond alors 400
     * sans rien appliquer.
     */
    public function verifyWebhookSignature(string $payload, string $signature): Event
    {
        return Webhook::constructEvent($payload, $signature, config('services.stripe.webhook_secret'));
    }

    /**
     * Applique l'effet d'un paiement réussi : `tier` passe à 'paid' et
     * `premium_until` est prolongé d'1 an (rectification client, 25/11/2026 —
     * la fiche payante n'est plus acquise définitivement, voir
     * TECHNICAL_DOCUMENTATION.md §83). Prend directement un `listing_id`
     * plutôt que l'objet Session Stripe complet, pour rester testable sans
     * construire un faux objet Stripe — voir tests/Feature/ListingPaymentTest.php.
     *
     * Prolongation à partir de la date de fin ACTUELLE si elle est encore
     * dans le futur (renouvellement anticipé : ne fait perdre aucun temps
     * déjà payé), sinon à partir d'aujourd'hui (premier paiement, ou
     * renouvellement après expiration).
     *
     * `$sessionId` sert uniquement à la vraie idempotence webhook : Stripe
     * peut renvoyer le MÊME évènement `checkout.session.completed` plusieurs
     * fois pour la même session — sans ce garde-fou, chaque renvoi
     * prolongerait `premium_until` d'une année supplémentaire à tort. Un
     * appel sans `$sessionId` (ex. test unitaire direct, geste admin) n'est
     * volontairement PAS protégé par ce garde-fou : il n'y a alors rien à
     * dédupliquer.
     */
    public function markListingAsPaid(int $listingId, ?string $sessionId = null): void
    {
        $listing = Listing::find($listingId);

        if (! $listing) {
            Log::channel('single')->warning("Webhook Stripe : fiche annuaire introuvable (id={$listingId}), paiement reçu mais non appliqué.");

            return;
        }

        if ($sessionId && $listing->stripe_last_processed_session_id === $sessionId) {
            return; // Webhook déjà traité pour cette session précise — idempotent.
        }

        $extendFrom = ($listing->premium_until && $listing->premium_until->isFuture())
            ? $listing->premium_until
            : now();

        $listing->tier = 'paid';
        $listing->paid_at = $listing->paid_at ?: now();
        $listing->premium_until = $extendFrom->copy()->addYear();
        $listing->renewal_reminder_sent_at = null;
        $listing->stripe_last_processed_session_id = $sessionId;
        $listing->save();
    }

    /** Extrait le `listing_id` des métadonnées d'une session Checkout complétée, ou `null` si absent/invalide. */
    public function listingIdFromSession(Session $session): ?int
    {
        $listingId = $session->metadata['listing_id'] ?? null;

        return $listingId ? (int) $listingId : null;
    }
}
