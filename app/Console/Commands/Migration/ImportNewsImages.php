<?php

namespace App\Console\Commands\Migration;

use App\Models\News;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réimporte les images d'actualités retrouvées sous `old/backEnd/public/news/`
 * (correction de l'audit initial — voir TECHNICAL_DOCUMENTATION.md §13).
 * `t_news.img_path`/`img_grand` stockent un nom de fichier nu (pas de préfixe
 * de répertoire, contrairement à `t_agendas.image`) — la commande met donc à
 * jour `news.image` avec le chemin relatif réel ("news/xxx.webp") une fois le
 * fichier retrouvé et copié.
 *
 * Environ 40% seulement des lignes ont un fichier retrouvable localement (le
 * reste n'a jamais été mis en cache dans ce dépôt de référence, ou a été
 * supprimé depuis) — voir le résumé affiché en fin de commande.
 */
class ImportNewsImages extends Command
{
    protected $signature = 'images:news';

    protected $description = 'Réimporte les images d\'actualités depuis old/backEnd/public/news/';

    public function handle(): int
    {
        $log = new MigrationLog('images-news');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/news')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous news/.");

        DB::connection('legacy')->table('t_news')
            ->select('id', 'img_path', 'img_grand')
            ->where(function ($q) {
                $q->whereNotNull('img_path')->where('img_path', '<>', '')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('img_grand')->where('img_grand', '<>', '');
                    });
            })
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($importer, $log) {
                foreach ($rows as $row) {
                    $news = News::where('legacy_id', $row->id)->first();
                    if (! $news) {
                        continue; // actualité non migrée (voir migrate:news)
                    }
                    if ($news->image && str_starts_with($news->image, 'news/')) {
                        continue; // déjà résolu par un run précédent
                    }

                    $legacyValue = $row->img_path ?: $row->img_grand;
                    $path = $importer->import($legacyValue, 'news');
                    if ($path) {
                        $news->update(['image' => $path]);
                        $log->updated("t_news#{$row->id} -> news#{$news->id} : {$path}");
                    } else {
                        $log->skipped("t_news#{$row->id} : fichier introuvable pour \"{$legacyValue}\".");
                    }
                }
            });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
