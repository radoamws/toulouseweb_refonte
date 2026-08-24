<?php

namespace App\Console\Commands\Migration;

use App\Models\Listing;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réimporte les photos de fiches annuaire retrouvées sous
 * `old/backEnd/public/article/` (+ `compressed/`) — correction de l'audit
 * initial, voir TECHNICAL_DOCUMENTATION.md §13. Deux sources legacy
 * distinctes, toutes deux rattachées à `t_article.id` :
 *   - `t_article.image` : photo principale -> collection MediaLibrary `logo`
 *     (singleFile) sur `Listing` ;
 *   - `t_carousel.image` (FK `id_article`) : galerie -> collection `gallery`
 *     (jamais migrée avant ce chantier : `t_carousel` n'avait pas de
 *     commande de migration dédiée, une vraie fiche annuaire peut pourtant
 *     porter plusieurs photos).
 *
 * IMPORTANT : `Spatie\MediaLibrary` supprime le fichier SOURCE après import
 * sauf si `->preservingOriginal()` est appelé — indispensable ici, `old/`
 * doit rester en lecture seule (voir brief).
 */
class ImportListingImages extends Command
{
    protected $signature = 'images:listings';

    protected $description = 'Réimporte les photos (principale + galerie) des fiches annuaire depuis old/backEnd/public/article/';

    public function handle(): int
    {
        $log = new MigrationLog('images-listings');
        $importer = new LegacyImageImporter([
            base_path('old/backEnd/public/article'),
            base_path('old/backEnd/public/article/compressed'),
        ]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous article/ (+ compressed/).");

        $this->importLogos($importer, $log);
        $this->importGallery($importer, $log);

        $this->info($log->summary());

        return self::SUCCESS;
    }

    protected function importLogos(LegacyImageImporter $importer, MigrationLog $log): void
    {
        $images = DB::connection('legacy')->table('t_article')
            ->whereNotNull('image')->where('image', '<>', '')
            ->pluck('image', 'id');

        Listing::whereNotNull('legacy_id')->whereIn('legacy_id', $images->keys())->chunk(200, function ($listings) use ($images, $importer, $log) {
            foreach ($listings as $listing) {
                if ($listing->getMedia('logo')->isNotEmpty()) {
                    continue; // déjà importé
                }

                $source = $importer->resolve($images[$listing->legacy_id]);
                if (! $source) {
                    $log->skipped("listings#{$listing->id} (legacy t_article#{$listing->legacy_id}) : photo principale introuvable.");

                    continue;
                }

                $listing->addMedia($source)->preservingOriginal()->toMediaCollection('logo');
                $log->updated("listings#{$listing->id} : logo <- {$source}");
            }
        });
    }

    protected function importGallery(LegacyImageImporter $importer, MigrationLog $log): void
    {
        $carousel = DB::connection('legacy')->table('t_carousel')
            ->whereNotNull('image')->where('image', '<>', '')
            ->orderBy('id')
            ->get()
            ->groupBy('id_article');

        Listing::whereNotNull('legacy_id')->whereIn('legacy_id', $carousel->keys())->chunk(200, function ($listings) use ($carousel, $importer, $log) {
            foreach ($listings as $listing) {
                $existingFilenames = $listing->getMedia('gallery')->pluck('file_name')->all();

                foreach ($carousel[$listing->legacy_id] as $row) {
                    $source = $importer->resolve($row->image);
                    if (! $source) {
                        $log->skipped("listings#{$listing->id} (legacy t_carousel#{$row->id}) : image de galerie introuvable pour \"{$row->image}\".");

                        continue;
                    }

                    if (in_array(basename($source), $existingFilenames, true)) {
                        continue; // déjà importée (run précédent)
                    }

                    $listing->addMedia($source)->preservingOriginal()->toMediaCollection('gallery');
                    $log->updated("listings#{$listing->id} : gallery <- {$source}");
                }
            }
        });
    }
}
