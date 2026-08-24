<?php

namespace App\Console\Commands\Migration;

use App\Models\Amenity;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les pictogrammes d'équipements (t_icone -> amenities.icon) depuis
 * `old/backEnd/public/icone/` — correction de l'audit initial qui affirmait
 * ces 19 fichiers introuvables (voir TECHNICAL_DOCUMENTATION.md §13) : ils
 * existent bien, simplement pas au chemin qui avait été exploré.
 */
class ImportAmenityIcons extends Command
{
    protected $signature = 'images:amenities';

    protected $description = 'Réimporte les pictogrammes d\'équipements depuis old/backEnd/public/icone/';

    public function handle(): int
    {
        $log = new MigrationLog('images-amenities');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/icone')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous icone/.");

        foreach (Amenity::whereNotNull('icon')->get() as $amenity) {
            if (str_starts_with($amenity->icon, 'amenities/')) {
                continue; // déjà résolu par un run précédent
            }

            $path = $importer->import($amenity->icon, 'amenities');
            if ($path) {
                $amenity->update(['icon' => $path]);
                $log->updated("amenities#{$amenity->id} : {$path}");
            } else {
                $log->skipped("amenities#{$amenity->id} : fichier introuvable pour \"{$amenity->icon}\".");
            }
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
