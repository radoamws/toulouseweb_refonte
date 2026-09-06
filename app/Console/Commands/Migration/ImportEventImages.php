<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les images d'événements retrouvées sous `old/backEnd/public/agenda/`
 * ET `old/backEnd/public/agendas/` (correction de l'audit initial — voir
 * TECHNICAL_DOCUMENTATION.md §13).
 *
 * ⚠️ Bug réel trouvé et corrigé (06/09/2026, audit de cutover prod §22) :
 * cette commande n'indexait QUE `agenda/` (sans "s") alors qu'une majorité
 * des valeurs `t_agendas.image`/`events.image` réelles commencent par le
 * préfixe `"agendas/"` (avec un "s") — un second dossier legacy distinct
 * (792 fichiers, 63 Mo), jusqu'ici considéré à tort comme un "doublon
 * probable, non prioritaire" dans une note antérieure. Vérifié : sur les
 * 897 lignes en échec du dernier run, 867 (96,7 %, 734 valeurs uniques)
 * portent ce préfixe et se résolvent à 100 % une fois `agendas/` indexé —
 * ce n'était pas un doublon, c'est la source manquante de ~82 % des images
 * d'événements.
 *
 * `t_agendas.image` mélange des URLs externes absolues (scraping tiers,
 * inchangées) et des chemins locaux déjà préfixés "agenda(s)/xxx.jpg" — la
 * commande retrouve le fichier réel (l'extension peut différer, ex. .webp
 * compressé côté legacy) et met à jour `events.image` en conséquence.
 */
class ImportEventImages extends Command
{
    protected $signature = 'images:events';

    protected $description = 'Réimporte les images d\'événements depuis old/backEnd/public/agenda(s)/';

    public function handle(): int
    {
        $log = new MigrationLog('images-events');
        $importer = new LegacyImageImporter([
            base_path('old/backEnd/public/agenda'),
            base_path('old/backEnd/public/agendas'),
        ]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous agenda/ + agendas/.");

        // Idempotent au niveau fichier (LegacyImageImporter ne recopie pas un
        // fichier déjà présent) : pas de filtre d'exclusion ici, la valeur
        // legacy porte déjà le préfixe "agenda/" dès la migration initiale
        // (migrate:events), impossible de la distinguer d'un chemin déjà
        // résolu par un run précédent de cette commande.
        Event::whereNotNull('image')
            ->where('image', 'not like', 'http%')
            ->chunk(200, function ($events) use ($importer, $log) {
                foreach ($events as $event) {
                    $path = $importer->import($event->image, 'agenda');
                    if ($path) {
                        $event->update(['image' => $path]);
                        $log->updated("events#{$event->id} : {$path}");
                    } else {
                        $log->skipped("events#{$event->id} : fichier introuvable pour \"{$event->image}\".");
                    }
                }
            });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
