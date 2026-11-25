<?php

namespace App\Http\Controllers;

use App\Services\Payments\StripePaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * Webhook Stripe (demande client, 24/11/2026, voir
 * TECHNICAL_DOCUMENTATION.md §83 pour la procédure de création de
 * l'endpoint côté dashboard Stripe). Route exemptée de CSRF (voir
 * bootstrap/app.php) — authenticité vérifiée par signature HMAC
 * (StripePaymentService::verifyWebhookSignature()).
 *
 * Seul `checkout.session.completed` est traité ; tout autre type
 * d'évènement reçu (Stripe peut en envoyer d'autres selon la configuration
 * du dashboard) répond 200 sans effet, pour que Stripe ne le retente pas
 * indéfiniment.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripePaymentService $stripe): Response
    {
        try {
            $event = $stripe->verifyWebhookSignature(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (Throwable $e) {
            Log::channel('single')->warning("Webhook Stripe : signature invalide ({$e->getMessage()}).");

            return response('', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var Session $session */
            $session = $event->data->object;
            $listingId = $stripe->listingIdFromSession($session);

            if ($listingId) {
                $stripe->markListingAsPaid($listingId, $session->id);
            }
        }

        return response('', 200);
    }
}
