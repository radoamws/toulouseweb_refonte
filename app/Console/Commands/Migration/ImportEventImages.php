<?php

namespace App\Console\Commands\Migration;

use App\Models\Event;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les images d'événements retrouvées sous `old/backEnd/public/agenda/`
 * (correction de l'audit initial — voir TECHNICAL_DOCUMENTATION.md §13).
 * `t_agendas.image` mélange des URLs externes absolues (scraping tiers,
 * inchangées) et des chemins locaux déjà préfixés "agenda/xxx.jpg" — la
 * commande retrouve le fichier réel (l'extension peut différer, ex. .webp
 * compressé côté legacy) et met à jour `events.image` en conséquence.
 */
class ImportEventImages extends Command
{
    protected $signature = 'images:events';

    protected $description = 'Réimporte les images d\'événements depuis old/backEnd/public/agenda/';

    public function handle(): int
    {
        $log = new MigrationLog('images-events');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/agenda')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous agenda/.");

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
