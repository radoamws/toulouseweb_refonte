<?php

namespace App\Console\Commands\Migration;

use App\Models\Movie;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réimporte les affiches de films retrouvées sous `old/backEnd/public/cine_film/`
 * (correction de l'audit initial — voir TECHNICAL_DOCUMENTATION.md §13, "Import
 * des images"). `t_cine_film.image` stocke soit une URL AlloCiné absolue (déjà
 * migrée telle quelle, rien à faire), soit un nom de fichier local déposé par
 * l'ancien scraper (`CinemaController::getIdFilm`, `public_path()."/cine_film"`)
 * — c'est ce second cas que cette commande retrouve et copie.
 *
 * Ne touche JAMAIS un `poster` déjà renseigné (URL absolue ou déjà importé) —
 * uniquement les films dont le poster actuel est vide/absent, pour ne jamais
 * écraser une donnée plus fraîche (ex. re-scrapée depuis AlloCiné, Phase 8).
 */
class ImportMovieImages extends Command
{
    protected $signature = 'images:movies';

    protected $description = 'Réimporte les affiches de films depuis old/backEnd/public/cine_film/';

    public function handle(): int
    {
        $log = new MigrationLog('images-movies');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/cine_film')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous cine_film/.");

        DB::connection('legacy')->table('t_cine_film')
            ->select('id', 'image')
            ->whereNotNull('image')
            ->where('image', '<>', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($importer, $log) {
                foreach ($rows as $row) {
                    if (str_starts_with($row->image, 'http://') || str_starts_with($row->image, 'https://') || str_starts_with($row->image, 'data:')) {
                        continue; // déjà une valeur exploitable telle quelle, migrée sans changement
                    }

                    $movie = Movie::where('legacy_id', $row->id)->first();
                    if (! $movie) {
                        continue; // film non migré (voir migrate:cinema)
                    }
                    if ($movie->poster && (str_starts_with($movie->poster, 'http://') || str_starts_with($movie->poster, 'https://') || str_starts_with($movie->poster, 'movies/'))) {
                        continue; // déjà une URL absolue (ex. re-scrapé) ou déjà résolu par un run précédent — ne pas écraser
                    }

                    $path = $importer->import($row->image, 'movies');
                    if ($path) {
                        $movie->update(['poster' => $path]);
                        $log->updated("t_cine_film#{$row->id} -> movies#{$movie->id} : {$path}");
                    } else {
                        $log->skipped("t_cine_film#{$row->id} : fichier introuvable pour \"{$row->image}\".");
                    }
                }
            });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
