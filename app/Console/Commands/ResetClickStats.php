<?php

namespace App\Console\Commands;

use App\Models\ClickEvent;
use Illuminate\Console\Command;

/**
 * Demande client : les statistiques de clics du dashboard admin doivent
 * repartir de zéro depuis cette refonte, pas continuer l'historique legacy
 * migré (`migrate:click-stats`, ~2,78M lignes `t_stat_counter`) — constaté en
 * direct sur la base de dev : 2 454 647 lignes `click_events` déjà présentes
 * (catégories, fiches annuaire, actualités, événements, sliders confondus)
 * avant ce correctif, ce qui aurait rendu les widgets du dashboard
 * (`ClicksOverview`/`ClicksByTypeChart`/`TopClickedEntities`) trompeurs dès
 * la mise en ligne (des mois de clics legacy comptés comme "aujourd'hui"/
 * "30 derniers jours" au premier chargement).
 *
 * Commande ponctuelle (pas un cron, voir routes/console.php) — à exécuter
 * UNE SEULE FOIS, juste avant/au moment de la bascule en production
 * ("cutover"), après que `migrate:click-stats` ait éventuellement tourné
 * (ou à la place, si on décide de ne jamais migrer l'historique). Les
 * widgets restent pleinement fonctionnels avec 0 ligne (déjà vérifié par
 * `AdminDashboardStatsTest::dashboard_renders_without_error_when_no_clicks_recorded`) —
 * "activer les stats" ne nécessitait donc aucun correctif de fonctionnement,
 * seulement cette remise à zéro des données.
 */
class ResetClickStats extends Command
{
    protected $signature = 'stats:reset {--force : Ne pas demander de confirmation}';

    protected $description = "Vide click_events pour repartir de zéro (les statistiques du dashboard doivent démarrer depuis cette refonte, pas l'historique legacy migré)";

    public function handle(): int
    {
        $count = ClickEvent::count();

        if ($count === 0) {
            $this->info('click_events est déjà vide — rien à faire.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Supprimer définitivement les {$count} lignes de click_events (historique de clics) ? Cette action est irréversible.")) {
            $this->comment('Annulé.');

            return self::SUCCESS;
        }

        ClickEvent::truncate();

        $this->info("{$count} lignes supprimées — les statistiques du dashboard repartent de zéro.");

        return self::SUCCESS;
    }
}
