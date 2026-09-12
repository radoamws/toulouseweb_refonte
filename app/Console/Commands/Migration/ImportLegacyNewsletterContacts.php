<?php

namespace App\Console\Commands\Migration;

use App\Models\NewsletterSubscriber;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reprend `toulouseweb_old.t_contacts` (1885 lignes, 2001-2014) dans la
 * newsletter de la refonte (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36) — PAS dans `contact_messages`
 * (App\Console\Commands\Migration\MigrateContacts a déjà jugé cette table
 * obsolète comme historique de messagerie, voir son docblock) : ici on ne
 * réutilise que l'email, comme point de départ d'une liste de diffusion,
 * pas comme conversation à conserver.
 *
 * Dédoublonnage sur l'email (insensible à la casse) : `t_contacts` contient
 * de nombreuses lignes pour la même personne ayant écrit plusieurs fois —
 * une seule inscription par email, quel que soit le nombre de messages
 * legacy. `legacy_id` retient la PREMIÈRE ligne t_contacts rencontrée pour
 * cet email (idempotence : un ré-import ne crée pas de doublon).
 *
 * Écrit en masse avec la MÊME prudence que les autres commandes de
 * migration (`withoutEvents` — voir MigrateListings/MigrateNews) même si
 * NewsletterSubscriber n'a aujourd'hui aucun observer coûteux : une
 * garantie qui coûte rien et évite une régression silencieuse si un
 * observer y est ajouté plus tard.
 */
class ImportLegacyNewsletterContacts extends Command
{
    protected $signature = 'newsletter:import-legacy-contacts';

    protected $description = "Importe les emails de toulouseweb_old.t_contacts comme abonnés newsletter (dédoublonnés)";

    public function handle(): int
    {
        $log = new MigrationLog('newsletter-legacy-contacts');
        $seenEmails = [];
        $created = 0;

        NewsletterSubscriber::withoutEvents(function () use ($log, &$seenEmails, &$created) {
            foreach (DB::connection('legacy')->table('t_contacts')->orderBy('id')->get() as $row) {
                $email = LegacyCleaner::text($row->email);

                if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $log->skipped("Contact legacy #{$row->id} : email absent/invalide.");

                    continue;
                }

                $email = mb_strtolower($email);

                if (isset($seenEmails[$email])) {
                    $log->skipped("Contact legacy #{$row->id} : email déjà vu dans cet import ({$email}).");

                    continue;
                }

                if (NewsletterSubscriber::where('email', $email)->exists()) {
                    $log->skipped("Contact legacy #{$row->id} : {$email} déjà abonné.");
                    $seenEmails[$email] = true;

                    continue;
                }

                $name = trim(($prenom = LegacyCleaner::text($row->prenom) ?? '').' '.(LegacyCleaner::text($row->nom) ?? ''));

                $subscriber = NewsletterSubscriber::create([
                    'email' => $email,
                    'name' => $name !== '' ? $name : null,
                    'status' => 'active',
                    'source' => 'legacy_import',
                    'subscribed_at' => LegacyCleaner::date($row->date) ?? now(),
                    'unsubscribe_token' => Str::random(48),
                    'legacy_id' => $row->id,
                ]);

                $seenEmails[$email] = true;
                $created++;
                $log->created("#{$row->id} -> #{$subscriber->id} ({$email})");
            }
        });

        $this->info($log->summary());
        $this->info("{$created} nouvel(le)s abonné(s) importé(s).");

        return self::SUCCESS;
    }
}
