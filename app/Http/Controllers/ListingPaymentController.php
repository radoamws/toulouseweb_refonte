<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\Payments\StripePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paiement Stripe d'une fiche annuaire (demande client, 24/11/2026, voir
 * TECHNICAL_DOCUMENTATION.md §83). Identifié par `edit_token` (même jeton
 * que l'espace client, voir ListingController::editSpace()) plutôt que par
 * l'id numérique de la fiche — évite qu'un visiteur puisse déclencher un
 * paiement sur une fiche qui n'est pas la sienne en devinant un id.
 *
 * Paiement autorisé dès la soumission (pas d'attente de validation admin,
 * contrairement au règlement manuel historique "à régler une fois votre
 * fiche validée") — demande client explicite : "autoriser un choix de
 * paiement immédiat aussi". En cas de refus admin (rare), un remboursement
 * Stripe se fait manuellement en un clic depuis le dashboard Stripe.
 *
 * Rectification client (25/11/2026) : la fiche payante est acquise pour 1 an,
 * pas définitivement (voir Listing::isPaid()/TECHNICAL_DOCUMENTATION.md §83)
 * — ce paiement est donc toujours autorisé, même pour une fiche déjà
 * payante : il prolonge `premium_until` d'un an de plus
 * (StripePaymentService::markListingAsPaid() prolonge à partir de
 * l'échéance actuelle si elle est encore future, jamais à partir
 * d'aujourd'hui dans ce cas, pour ne faire perdre aucun temps déjà payé).
 */
class ListingPaymentController extends Controller
{
    public function checkout(string $token, StripePaymentService $stripe): RedirectResponse
    {
        $listing = Listing::where('edit_token', $token)->firstOrFail();

        if (! config('services.stripe.enabled')) {
            // Flag STRIPE_PAYMENTS_ENABLED=false (demande client, le temps
            // qu'il crée son compte Stripe, voir config/services.php) —
            // rien ne doit bloquer la fiche elle-même, seul le paiement en
            // ligne immédiat est indisponible ; le règlement manuel après
            // validation reste possible comme avant.
            return redirect()->route('annuaire.editSpace', $token)
                ->with('status', "Le paiement en ligne n'est pas encore activé — nous vous contacterons pour le règlement une fois votre fiche validée.");
        }

        try {
            $url = $stripe->createCheckoutSessionUrl(
                $listing,
                route('annuaire.listings.checkout.success', $token),
                route('annuaire.listings.checkout.cancel', $token),
            );
        } catch (Throwable $e) {
            Log::channel('single')->warning("Échec de création de session Stripe Checkout (fiche #{$listing->id}): {$e->getMessage()}");

            return redirect()->route('annuaire.editSpace', $token)
                ->with('status', "Le paiement en ligne est momentanément indisponible, réessayez plus tard ou contactez-nous.");
        }

        return redirect()->away($url);
    }

    /**
     * Simple page de retour — le passage effectif à `tier = 'paid'` se fait
     * uniquement via le webhook (source de vérité serveur-à-serveur, jamais
     * ce paramètre de redirection navigateur, trivialement falsifiable).
     */
    public function success(string $token): RedirectResponse
    {
        Listing::where('edit_token', $token)->firstOrFail();

        return redirect()->route('annuaire.editSpace', $token)
            ->with('status', 'Paiement reçu, merci ! Votre fiche conserve ses options complètes pour 1 an (la confirmation peut prendre quelques instants) — vous recevrez un rappel avant l\'échéance pour renouveler.');
    }

    public function cancel(string $token): RedirectResponse
    {
        Listing::where('edit_token', $token)->firstOrFail();

        return redirect()->route('annuaire.editSpace', $token)
            ->with('status', 'Paiement annulé — votre fiche reste inchangée, vous pouvez réessayer à tout moment.');
    }
}
