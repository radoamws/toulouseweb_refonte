<?php

namespace App\Console\Commands\Migration;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsComment;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 5 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : actualités
 * (t_news -> news) + commentaires. Nécessite `migrate:reference-data` avant
 * (news_categories, via legacy_code puisque t_news_cat.id est un varchar).
 *
 * Statut legacy (`is_enabled` char) mappé ainsi (voir audit DB §2.1) :
 * D (disabled) -> archived, T (ok) -> published, F (en attente) -> pending,
 * S (refusé) -> archived (pas d'équivalent "rejected" pour les news).
 */
class MigrateNews extends Command
{
    protected $signature = 'migrate:news';

    protected $description = 'Migre les actualités (t_news) et leurs commentaires depuis toulouseweb_old';

    protected const STATUS_MAP = ['T' => 'published', 'F' => 'pending', 'D' => 'archived', 'S' => 'archived'];

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $log = new MigrationLog('news');
        $categoryMap = NewsCategory::whereNotNull('legacy_code')->pluck('id', 'legacy_code');
        $newsIdByLegacyId = [];

        News::withoutEvents(function () use ($log, $categoryMap, &$newsIdByLegacyId) {
            DB::connection('legacy')->table('t_news')->orderBy('id')->chunk(500, function ($rows) use ($log, $categoryMap, &$newsIdByLegacyId) {
                foreach ($rows as $row) {
                    $title = LegacyCleaner::text($row->titre);
                    if (! $title) {
                        $log->skipped("Actualité legacy #{$row->id} sans titre — ignorée.");

                        continue;
                    }

                    $body = LegacyCleaner::text($row->descr) ?? '';
                    $status = self::STATUS_MAP[$row->is_enabled] ?? 'draft';
                    $publishedAt = LegacyCleaner::date($row->timestamp);

                    $existing = News::withTrashed()->where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug ?: $row->slug_old, $title, 'news', $existing?->id);

                    $news = News::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'category_id' => $categoryMap[$row->type] ?? null,
                            'title' => $title,
                            'slug' => $slug,
                            'excerpt' => LegacyCleaner::text($row->descr_court),
                            'body' => $body,
                            'image' => LegacyCleaner::text($row->img_path) ?? LegacyCleaner::text($row->img_grand),
                            'status' => $status,
                            'published_at' => $status === 'published' ? $publishedAt : null,
                        ]
                    );
                    $newsIdByLegacyId[$row->id] = $news->id;
                    $existing ? $log->updated("#{$row->id} -> #{$news->id} ({$title})") : $log->created("#{$row->id} -> #{$news->id} ({$title})");
                }
            });
        });

        $this->info($log->summary());
        $this->migrateComments($newsIdByLegacyId);

        return self::SUCCESS;
    }

    protected function migrateComments(array $newsIdByLegacyId): void
    {
        $log = new MigrationLog('news-comments');

        foreach (DB::connection('legacy')->table('t_news_comment')->orderBy('id')->get() as $row) {
            if ((int) $row->is_deleted === 1) {
                $log->skipped("Commentaire legacy #{$row->id} marqué supprimé — ignoré.");

                continue;
            }
            $newsId = $newsIdByLegacyId[$row->id_news] ?? null;
            if (! $newsId) {
                $log->skipped("Commentaire legacy #{$row->id} : actualité legacy #{$row->id_news} introuvable.");

                continue;
            }

            $existing = NewsComment::where('legacy_id', $row->id)->first();
            $comment = NewsComment::updateOrCreate(
                ['legacy_id' => $row->id],
                [
                    'news_id' => $newsId,
                    'author_name' => LegacyCleaner::text($row->pseudo) ?? 'Anonyme',
                    'body' => LegacyCleaner::text($row->commentaire) ?? '',
                    'status' => (int) $row->statut === 1 ? 'published' : 'pending',
                ]
            );
            $existing ? $log->updated("#{$row->id} -> #{$comment->id}") : $log->created("#{$row->id} -> #{$comment->id}");
        }

        $this->info($log->summary());
    }
}
