<?php

namespace App\Console\Commands\Migration;

use App\Models\News;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réimporte les images d'actualités retrouvées sous `old/backEnd/public/news/`
 * — ET, depuis le 07/09/2026 (correctif de cutover prod, voir
 * TECHNICAL_DOCUMENTATION.md §22), sous `agenda/`, `agendas/`, `article/` et
 * `bonsplans/` : une partie des actualités (surtout les plus RÉCENTES,
 * publiées après la capture de ce dépôt `old/` de référence) utilisent en
 * réalité un visuel déjà uploadé pour un événement/une fiche annuaire — le
 * site semble partager un même pool d'images entre actualités/agenda/annuaire
 * plutôt que d'avoir un dossier `news/` strictement dédié. Vérifié : sur 226
 * actualités PUBLIÉES avec une image non résolue, 21 se retrouvent ainsi
 * (14 dans `agendas/`, 3 dans `article/`, 3 dans `agenda/`, 1 dans
 * `bonsplans/`) — même mécanisme que le bug déjà corrigé sur `ImportEventImages`
 * (répertoire de recherche manquant), mais amélioration partielle seulement
 * ici : les ~90% restants (205/226) sont réellement absents de ce dépôt de
 * référence, pas un problème de code — nécessite un accès au vrai stockage
 * de production pour être résolu.
 *
 * `t_news.img_path`/`img_grand` stockent un nom de fichier nu (pas de préfixe
 * de répertoire, contrairement à `t_agendas.image`) — la commande met donc à
 * jour `news.image` avec le chemin relatif réel ("news/xxx.webp") une fois le
 * fichier retrouvé et copié.
 */
class ImportNewsImages extends Command
{
    protected $signature = 'images:news';

    protected $description = 'Réimporte les images d\'actualités depuis old/backEnd/public/news/ (+ agenda(s)/article/bonsplans en repli)';

    public function handle(): int
    {
        $log = new MigrationLog('images-news');
        $importer = new LegacyImageImporter([
            base_path('old/backEnd/public/news'),
            base_path('old/backEnd/public/agenda'),
            base_path('old/backEnd/public/agendas'),
            base_path('old/backEnd/public/article'),
            base_path('old/backEnd/public/bonsplans'),
        ]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous news/ + agenda(s)/ + article/ + bonsplans/.");

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
