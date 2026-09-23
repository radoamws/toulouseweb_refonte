<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Point d'entrée unique pour le déclenchement HTTP du WebCron Infomaniak
 * (voir App\Http\Controllers\WebCronController et
 * TECHNICAL_DOCUMENTATION.md §27).
 *
 * Contexte : l'hébergement mutualisé ne permet pas de crontab serveur
 * (confirmé : `crontab -l` refusé pour ce compte — "not allowed to use
 * this program"), seulement un "Planificateur de tâches" (WebCron) qui
 * appelle une URL à une fréquence choisie dans son interface — au mieux
 * une fois par jour sur ce plan. On ne peut donc plus s'appuyer sur
 * `Schedule::` de routes/console.php pour la production : ce mécanisme
 * suppose `schedule:run` invoqué au moins chaque minute pour pouvoir
 * faire correspondre les horaires précis (`dailyAt('05:00')`, `weekly()`
 * = dimanche minuit...) au moment exact de l'invocation — un déclenchement
 * unique et quotidien à une heure fixe (ex. 8h) ne matcherait jamais ces
 * horaires-là.
 *
 * Cette commande contourne le problème : elle exécute directement, dans
 * l'ordre, tout ce qui doit tourner au moins une fois par jour, sans
 * dépendre du timing exact de l'invocation. `routes/console.php` reste
 * inchangé (utile en local via `php artisan schedule:work`, et repris tel
 * quel si l'hébergement change un jour pour un vrai cron serveur) — les
 * deux mécanismes ne se recoupent pas en production puisque rien n'y
 * invoque `schedule:run`.
 *
 * Toutes les commandes appelées sont idempotentes/sûres à rejouer (upsert
 * pour les scrapers, `--stop-when-empty` pour la file, régénération de
 * fichier pour le sitemap) au cas où le WebCron serait configuré plus
 * souvent qu'une fois par jour.
 */
class RunWebCron extends Command
{
    protected $signature = 'webcron:run';

    protected $description = "Exécute les tâches planifiées de l'application (déclenché par le WebCron Infomaniak)";

    public function handle(): int
    {
        $this->call('queue:work', ['--stop-when-empty' => true]);
        $this->call('sitemap:generate');
        $this->call('scrape:cinema');
        $this->call('scrape:events');
        // Doublons agenda (demande client, 19-23/09/2026, voir
        // TECHNICAL_DOCUMENTATION.md §47/§55) — trouvé le 23/09/2026 : ce
        // n'est PAS un artefact ponctuel de migration, OpenAgenda republie
        // RÉELLEMENT le même événement sous un nouveau slug d'un jour sur
        // l'autre (nouveaux doublons apparus depuis le dernier nettoyage
        // manuel, quelques heures plus tôt) — doit donc tourner à CHAQUE
        // exécution du WebCron, juste après scrape:events, pas seulement en
        // ponctuel. Les deux commandes sont idempotentes (voir leur
        // docblock respectif).
        $this->call('content:dedupe-agenda-manual-entries');
        $this->call('content:dedupe-duplicate-events');
        $this->call('content:mark-expired');

        // Hebdomadaire (brief §15) : `Schedule::weekly()` ne peut plus matcher
        // un timing précis sans cron par minute (voir docblock ci-dessus) —
        // on ne lance cet audit que le jour où le WebCron quotidien tombe un
        // dimanche, plutôt que de le lancer tous les jours sans distinction.
        if (now()->isSunday()) {
            $this->call('redirects:audit');
        }

        return self::SUCCESS;
    }
}
