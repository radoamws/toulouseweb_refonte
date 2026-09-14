<?php

namespace App\Services\Newsletter;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi d'une campagne newsletter (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36). Deux modes volontairement séparés :
 *
 * - `sendTest()` : envoi SYNCHRONE (pas de file), toujours autorisé, jamais
 *   vers un vrai abonné — uniquement vers `services.newsletter.test_recipients`
 *   (config, par défaut rado.rakotoarivelo@amws.space). C'est le seul mode
 *   utilisable tant que le client n'a pas donné son "GO".
 * - `sendToAll()` : met en FILE un envoi par abonné actif, refuse de le
 *   faire si `services.newsletter.sending_enabled` est à false (voir
 *   config/services.php pour la justification complète du flag).
 *
 * ⚠️ Toujours `Mail::mailer('brevo')->to(...)->...` — JAMAIS `Mail::to(...)`
 * seul : voir le docblock de App\Mail\NewsletterMail (bug réel trouvé le
 * 14/09/2026, TECHNICAL_DOCUMENTATION.md §40) — sans le `mailer('brevo')`
 * choisi ICI, en amont, Laravel route silencieusement vers le mailer par
 * défaut ('smtp', la boîte Infomaniak). `sendTest()` utilise `sendNow()`
 * (pas `send()`) : `NewsletterMail` implémente `ShouldQueue`, et
 * `Mailer::send()` MET TOUJOURS EN FILE un mailable `ShouldQueue` (même
 * appelé via `->send()`, pas seulement `->queue()` — vérifié dans
 * `Illuminate\Mail\Mailer::sendMailable()`) ; seul `sendNow()` envoie
 * réellement de façon synchrone quel que soit `ShouldQueue`. Cette
 * confusion (`send()` vs `sendNow()` sur un mailable `ShouldQueue`) a fait
 * que TOUS les tests précédents (SMTP direct, SMTP Brevo, API Brevo)
 * étaient en réalité mis en file sans jamais être traités (aucun
 * `queue:work` déclenché entre-temps) — jamais un problème de délivrabilité
 * SMTP/API à proprement parler.
 */
class NewsletterSender
{
    /** @return int Nombre d'emails de test envoyés. */
    public function sendTest(Newsletter $newsletter): int
    {
        $recipients = config('services.newsletter.test_recipients', []);

        if (empty($recipients)) {
            return 0;
        }

        // Un abonné "virtuel" (jamais persisté) juste pour que le lien de
        // désinscription du gabarit reste valide dans l'aperçu envoyé —
        // sans jamais créer de vraie ligne newsletter_subscribers pour un
        // simple test.
        $previewSubscriber = new NewsletterSubscriber([
            'email' => $recipients[0],
            'unsubscribe_token' => 'apercu-test',
        ]);

        foreach ($recipients as $email) {
            Mail::mailer('brevo')->to($email)->sendNow(new NewsletterMail($newsletter, $previewSubscriber));
        }

        $newsletter->update([
            'status' => $newsletter->sent_at ? $newsletter->status : 'draft',
            'test_sent_at' => now(),
        ]);

        return count($recipients);
    }

    /**
     * @throws \RuntimeException si l'envoi général n'est pas activé (voir
     * docblock de la classe et config/services.php).
     */
    public function sendToAll(Newsletter $newsletter): int
    {
        if (! config('services.newsletter.sending_enabled')) {
            throw new \RuntimeException(
                "L'envoi à tous les abonnés est désactivé (NEWSLETTER_SENDING_ENABLED=false) — ".
                'en attente du GO du client, voir config/services.php.'
            );
        }

        $subscribers = NewsletterSubscriber::active()->get();

        foreach ($subscribers as $subscriber) {
            Mail::mailer('brevo')->to($subscriber->email)->queue(new NewsletterMail($newsletter, $subscriber));
        }

        $newsletter->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipient_count' => $subscribers->count(),
        ]);

        return $subscribers->count();
    }
}
