<?php

namespace App\Console\Commands\Newsletter;

use App\Models\NewsletterSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Marque comme `invalid` les abonnés dont l'email a échoué DÉFINITIVEMENT
 * lors d'un envoi Brevo (demande client, 15/09/2026 : "si possible de
 * récupérer les contacts dans Brevo où les mails ne fonctionnent plus" —
 * voir TECHNICAL_DOCUMENTATION.md §44).
 *
 * Ne considère QUE `hardBounces` (adresse définitivement invalide — boîte
 * fermée, domaine inexistant...) et `blocked` (Brevo refuse volontairement
 * de réessayer, ex. plainte spam antérieure) — PAS `softBounces`
 * (échec temporaire, ex. "connection timeout"/boîte pleine : la même
 * adresse peut très bien recevoir le prochain envoi sans problème, la
 * marquer invalide serait une perte de contact injustifiée).
 *
 * L'envoi transactionnel (voir App\Mail\Transport\BrevoApiTransport)
 * n'alimente PAS automatiquement le carnet de contacts Brevo (vérifié :
 * `GET /v3/contacts` ne montre que le compte lui-même) — les statistiques
 * de délivrabilité (`GET /v3/smtp/statistics/events`) sont donc la SEULE
 * source Brevo exploitable ici, pas une synchronisation de contacts au
 * sens propre.
 */
class SyncBrevoBounces extends Command
{
    protected $signature = 'newsletter:sync-brevo-bounces {--days=30 : Fenêtre de recherche, en jours}';

    protected $description = "Marque comme invalides les abonnés dont l'email a définitivement échoué (hard bounce/blocked) sur Brevo";

    public function handle(): int
    {
        $apiKey = config('services.brevo.api_key');
        if (! $apiKey) {
            $this->error('BREVO_API_KEY non configurée — voir config/services.php.');

            return self::FAILURE;
        }

        $from = now()->subDays((int) $this->option('days'))->toDateString();
        $to = now()->toDateString();

        $invalidEmails = array_unique(array_merge(
            $this->fetchEmails($apiKey, $from, $to, 'hardBounces'),
            $this->fetchEmails($apiKey, $from, $to, 'blocked'),
        ));

        $updated = 0;
        foreach ($invalidEmails as $email) {
            // ->update() sur le query builder (pas Eloquent ->save() en
            // boucle) — pattern déjà établi dans ce projet pour les écritures
            // en lot, voir les commandes migrate:*/content:* équivalentes.
            $updated += NewsletterSubscriber::where('email', $email)
                ->where('status', 'active')
                ->update(['status' => 'invalid']);
        }

        $this->info(count($invalidEmails)." email(s) définitivement invalide(s) trouvé(s) sur Brevo (fenêtre de {$this->option('days')} jours), {$updated} abonné(s) mis à jour.");

        return self::SUCCESS;
    }

    /** @return string[] Emails en minuscules, dédoublonnés. */
    protected function fetchEmails(string $apiKey, string $from, string $to, string $event): array
    {
        $emails = [];
        $limit = 500;
        $offset = 0;

        do {
            $response = Http::withHeaders(['api-key' => $apiKey])
                ->get('https://api.brevo.com/v3/smtp/statistics/events', [
                    'startDate' => $from,
                    'endDate' => $to,
                    'event' => $event,
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if ($response->failed()) {
                $this->warn("Échec de la requête Brevo ({$event}, offset {$offset}) : {$response->status()} {$response->body()}");
                break;
            }

            $batch = $response->json('events') ?? [];
            foreach ($batch as $row) {
                if (! empty($row['email'])) {
                    $emails[] = mb_strtolower($row['email']);
                }
            }

            $offset += $limit;
        } while (count($batch) === $limit);

        return array_values(array_unique($emails));
    }
}
