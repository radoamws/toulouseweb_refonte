<?php

namespace App\Console\Commands;

use App\Models\MissedRedirect;
use Illuminate\Console\Command;

/**
 * Repère les 404 fréquentes sans redirection associée (brief §15, cron
 * hebdomadaire — voir TECHNICAL_DOCUMENTATION.md §11). S'appuie sur
 * `missed_redirects`, alimentée par Controller::redirectOrAbort() et
 * RedirectFallbackController à chaque 404 réelle (voir leurs docblocks) —
 * sans ce journal, "repérer les 404 fréquentes" n'aurait aucune donnée à
 * exploiter.
 *
 * Volontairement une simple liste triée par fréquence, pas une
 * automatisation : décider qu'une 404 mérite une redirection (et vers où)
 * reste un jugement humain, à faire depuis l'admin (RedirectResource).
 */
class AuditRedirects extends Command
{
    protected $signature = 'redirects:audit {--min-hits=3 : Ne montrer que les chemins vus au moins N fois} {--limit=25 : Nombre maximum de lignes affichées}';

    protected $description = 'Liste les 404 les plus fréquentes sans redirection associée, candidates à traiter dans RedirectResource';

    public function handle(): int
    {
        $rows = MissedRedirect::query()
            ->where('hits_count', '>=', (int) $this->option('min-hits'))
            ->orderByDesc('hits_count')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Aucune 404 récurrente au-dessus du seuil — rien à traiter.');

            return self::SUCCESS;
        }

        $this->table(
            ['Chemin', 'Occurrences', 'Vue la 1ère fois', 'Vue la dernière fois'],
            $rows->map(fn (MissedRedirect $m) => [
                $m->path,
                $m->hits_count,
                $m->first_seen_at?->format('Y-m-d') ?? '—',
                $m->last_seen_at?->format('Y-m-d') ?? '—',
            ])
        );

        $this->info("{$rows->count()} chemin(s) affiché(s) — à traiter manuellement dans l'admin (RedirectResource) si pertinent.");

        return self::SUCCESS;
    }
}
