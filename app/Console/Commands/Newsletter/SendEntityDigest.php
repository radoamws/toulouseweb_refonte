<?php

namespace App\Console\Commands\Newsletter;

use App\Services\Newsletter\EntityDigestSender;
use App\Services\Newsletter\NewsletterSender;
use Illuminate\Console\Command;

/**
 * Construit (si besoin) et envoie automatiquement le digest quotidien des
 * fiches annuaire/actualités/annonces/événements validées dans la journée
 * (demande client, 12/11/2026 : "faire un envoi automatique... sans mon
 * intervention") — appelée une fois par jour par `webcron:run`
 * (App\Console\Commands\RunWebCron), jamais directement par un
 * administrateur.
 *
 * Respecte volontairement `NEWSLETTER_SENDING_ENABLED` : si désactivé, le
 * digest est quand même créé en `draft` (visible dans Filament, envoyable
 * à la main si besoin), mais l'envoi automatique est sauté — c'est le
 * coupe-circuit général déjà en place pour "Envoyer à tous les abonnés"
 * (voir config/services.php), le client a demandé explicitement de le
 * garder même pour cette automatisation.
 */
class SendEntityDigest extends Command
{
    protected $signature = 'newsletter:send-entity-digest';

    protected $description = 'Regroupe les brouillons du jour (annuaire/actualités/annonces/événements) et les envoie automatiquement à tous les abonnés';

    public function handle(EntityDigestSender $builder, NewsletterSender $sender): int
    {
        $digest = $builder->build();

        if (! $digest) {
            $this->info('Rien à envoyer aujourd\'hui.');

            return self::SUCCESS;
        }

        if (! config('services.newsletter.sending_enabled')) {
            $this->warn("Digest créé en brouillon (newsletter #{$digest->id}) mais NEWSLETTER_SENDING_ENABLED=false — envoi automatique sauté, à envoyer manuellement depuis Filament si besoin.");

            return self::SUCCESS;
        }

        $count = $sender->sendToAll($digest);
        $this->info("Digest envoyé à {$count} abonné(s) (newsletter #{$digest->id}).");

        return self::SUCCESS;
    }
}
