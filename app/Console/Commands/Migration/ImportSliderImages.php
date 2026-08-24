<?php

namespace App\Console\Commands\Migration;

use App\Models\Slider;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les images de sliders retrouvables localement. Contrairement aux
 * autres domaines, `t_sliders.img` ne correspond à aucun répertoire dédié —
 * les rares fichiers retrouvés sont dispersés (constaté dans `cinema/`,
 * `article/`, `bonsplans/images/`...), d'où une recherche sur l'ensemble de
 * `old/backEnd/public/` plutôt qu'un sous-répertoire précis.
 *
 * Sur 135 sliders, seuls quelques-uns ont un fichier correspondant dans ce
 * dépôt (les ~2 déjà importés manuellement lors d'un précédent lot de
 * travail, retrouvés ici aussi, plus 1-2 nouveaux) — l'écrasante majorité
 * des visuels de sliders n'existe que sur le serveur de production, voir
 * TECHNICAL_DOCUMENTATION.md §13.
 */
class ImportSliderImages extends Command
{
    protected $signature = 'images:sliders';

    protected $description = 'Réimporte les images de sliders retrouvables sous old/backEnd/public/ (recherche large)';

    public function handle(): int
    {
        $log = new MigrationLog('images-sliders');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous old/backEnd/public/.");

        foreach (Slider::whereNotNull('image')->get() as $slider) {
            if (str_starts_with($slider->image, 'sliders/')) {
                continue; // déjà résolu (import manuel précédent ou run précédent de cette commande)
            }

            $path = $importer->import($slider->image, 'sliders');
            if ($path) {
                $slider->update(['image' => $path]);
                $log->updated("sliders#{$slider->id} : {$path}");
            } else {
                $log->skipped("sliders#{$slider->id} : fichier introuvable pour \"{$slider->image}\".");
            }
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
