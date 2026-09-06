<?php

namespace App\Console\Commands\Migration;

use App\Models\EventCategory;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les icônes de catégories d'événements (t_agenda_categories.icon
 * -> event_categories.icon) depuis `old/backEnd/public/agenda(s)/` — même
 * dossier que les images d'événements (`ImportEventImages`), les icônes de
 * catégorie (`soiree.png`, `musique.png`, `theatre.png`...) y sont mêlées
 * aux visuels d'événements individuels.
 *
 * ⚠️ Gap réel trouvé (06/09/2026, audit de cutover prod §22) : cette
 * commande n'existait pas avant ce correctif — `migrate:reference-data`
 * copie déjà `t_agenda_categories.icon` tel quel (ex. "agenda/soiree.png")
 * dans `event_categories.icon`, mais ce chemin n'a jamais été résolu ni
 * copié vers `storage/app/public/` : les icônes de catégorie d'agenda
 * étaient donc cassées (URL construite vers un fichier inexistant) partout
 * où elles sont affichées (front, admin).
 */
class ImportEventCategoryIcons extends Command
{
    protected $signature = 'images:event-categories';

    protected $description = "Réimporte les icônes de catégories d'événements depuis old/backEnd/public/agenda(s)/";

    public function handle(): int
    {
        $log = new MigrationLog('images-event-categories');
        $importer = new LegacyImageImporter([
            base_path('old/backEnd/public/agenda'),
            base_path('old/backEnd/public/agendas'),
        ]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous agenda/ + agendas/.");

        foreach (EventCategory::whereNotNull('icon')->get() as $category) {
            if (str_starts_with($category->icon, 'event-categories/')) {
                continue; // déjà résolu par un run précédent
            }

            $path = $importer->import($category->icon, 'event-categories');
            if ($path) {
                $category->update(['icon' => $path]);
                $log->updated("event_categories#{$category->id} : {$path}");
            } else {
                $log->skipped("event_categories#{$category->id} : fichier introuvable pour \"{$category->icon}\".");
            }
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
