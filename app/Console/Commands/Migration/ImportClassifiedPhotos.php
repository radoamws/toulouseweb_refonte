<?php

namespace App\Console\Commands\Migration;

use App\Models\Classified;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réimporte les photos d'annonces (t_annonce.image, une seule par annonce)
 * depuis `old/backEnd/public/annonce/` — commande manquante trouvée à
 * l'audit de cutover prod du 06/09/2026 (voir MigrateClassifieds).
 *
 * IMPORTANT : `Spatie\MediaLibrary` supprime le fichier SOURCE après import
 * sauf si `->preservingOriginal()` est appelé — indispensable ici, `old/`
 * doit rester en lecture seule (voir brief).
 */
class ImportClassifiedPhotos extends Command
{
    protected $signature = 'images:classifieds';

    protected $description = "Réimporte les photos d'annonces depuis old/backEnd/public/annonce/";

    public function handle(): int
    {
        $log = new MigrationLog('images-classifieds');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/annonce')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous annonce/.");

        $images = DB::connection('legacy')->table('t_annonce')
            ->whereNotNull('image')->where('image', '<>', '')
            ->pluck('image', 'id');

        Classified::whereNotNull('legacy_id')->whereIn('legacy_id', $images->keys())->chunk(200, function ($classifieds) use ($images, $importer, $log) {
            foreach ($classifieds as $classified) {
                if ($classified->getMedia('photos')->isNotEmpty()) {
                    continue; // déjà importée (run précédent)
                }

                $source = $importer->resolve($images[$classified->legacy_id]);
                if (! $source) {
                    $log->skipped("classifieds#{$classified->id} (legacy t_annonce#{$classified->legacy_id}) : photo introuvable pour \"{$images[$classified->legacy_id]}\".");

                    continue;
                }

                $classified->addMedia($source)->preservingOriginal()->toMediaCollection('photos');
                $log->updated("classifieds#{$classified->id} : photos <- {$source}");
            }
        });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
