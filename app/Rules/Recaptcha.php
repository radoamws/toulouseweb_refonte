<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * reCAPTCHA v3 (demande client, 19/09/2026) — protège les formulaires
 * publics contre le spam EN COMPLÉMENT du honeypot déjà en place sur chacun
 * (jamais en remplacement, voir ClassifiedController::store() etc.).
 * v3 est invisible : pas de case à cocher, un jeton généré côté client
 * (resources/js/recaptcha.js) est vérifié ici auprès de l'API Google
 * (`siteverify`), qui renvoie un score de confiance 0 (bot) à 1 (humain).
 *
 * Deux cas volontairement "fail-open" (jamais bloquer un vrai visiteur) :
 *   1. `services.recaptcha.secret_key` vide — reCAPTCHA pas configuré sur cet
 *      environnement (dev local sans clé, CI, ou pas encore renseigné en
 *      prod) : la règle ne fait rien, même comportement qu'AdminNotifier
 *      sans destinataire configuré.
 *   2. L'appel réseau vers Google échoue (timeout, service indisponible) :
 *      journalisé, mais la soumission n'est PAS bloquée — le honeypot +
 *      le throttle restent la protection de base, une panne côté Google ne
 *      doit jamais faire perdre une vraie annonce/un vrai contact.
 *
 * `action` doit correspondre exactement à celui envoyé par
 * `grecaptcha.execute(siteKey, {action})` côté JS (attribut
 * `data-recaptcha-action` sur le `<form>`) — une action différente indique
 * un jeton généré pour un AUTRE formulaire, rejeté.
 */
class Recaptcha implements ValidationRule
{
    public function __construct(private readonly string $action) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secretKey = config('services.recaptcha.secret_key');
        if (! $secretKey) {
            return;
        }

        if (! $value) {
            $fail('Vérification anti-robot manquante, merci de réessayer.');

            return;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secretKey,
                'response' => $value,
            ])->json();
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA : vérification injoignable, soumission acceptée par défaut.', [
                'action' => $this->action,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if (! ($response['success'] ?? false)) {
            $fail('Vérification anti-robot échouée, merci de réessayer.');

            return;
        }

        if (($response['action'] ?? null) !== $this->action) {
            $fail('Vérification anti-robot invalide, merci de réessayer.');

            return;
        }

        $minScore = (float) config('services.recaptcha.min_score', 0.5);
        if (($response['score'] ?? 0.0) < $minScore) {
            $fail('Vérification anti-robot échouée, merci de réessayer.');
        }
    }
}
