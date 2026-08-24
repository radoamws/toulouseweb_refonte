<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

/**
 * Génère public/sitemap.xml (brief §13) à partir du contenu réellement
 * public/indexable. Fichier statique régénéré par cron (voir
 * TECHNICAL_DOCUMENTATION.md §11) plutôt que calculé à chaque requête
 * (contrairement à SiteMapController::generate du legacy, sans cache —
 * voir audit backend §4).
 *
 * N'inclut PAS : les 18k+ événements historiques expirés, les 17k+ films
 * sans lien direct avec une page indexable pérenne au-delà de leurs
 * séances courantes (évite un sitemap disproportionné par rapport au
 * contenu réellement pertinent pour les moteurs de recherche — à revoir
 * si le volume de contenu réel augmente significativement).
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Régénère public/sitemap.xml à partir du contenu public actuel';

    public function handle(): int
    {
        $sitemap = Sitemap::create()
            ->add(Url::create('/')->setPriority(1.0)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));

        foreach (['/annuaire', '/agenda', '/cinema', '/actualites', '/annonces', '/contact'] as $path) {
            $sitemap->add(Url::create($path)->setPriority(0.8)->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY));
        }

        Category::where('is_active', true)->each(
            fn (Category $c) => $sitemap->add(Url::create("/annuaire/{$c->slug}")->setLastModificationDate($c->updated_at)->setPriority(0.7))
        );

        Listing::published()->each(
            fn (Listing $l) => $sitemap->add(Url::create("/annuaire/fiche/{$l->slug}")->setLastModificationDate($l->updated_at)->setPriority($l->isPaid() ? 0.7 : 0.5))
        );

        EventCategory::each(
            fn (EventCategory $c) => $sitemap->add(Url::create("/agenda/{$c->slug}")->setLastModificationDate($c->updated_at)->setPriority(0.6))
        );

        Event::published()->upcoming()->each(
            fn (Event $e) => $sitemap->add(Url::create("/agenda/{$e->slug}")->setLastModificationDate($e->updated_at)->setPriority(0.6))
        );

        Cinema::where('is_active', true)->each(
            fn (Cinema $c) => $sitemap->add(Url::create("/cinema/salles/{$c->slug}")->setLastModificationDate($c->updated_at)->setPriority(0.6))
        );

        Movie::whereHas('screenings', function ($q) {
            $q->where(fn ($q2) => $q2->whereNull('start_date')->orWhere('start_date', '<=', now()))
                ->where(fn ($q2) => $q2->whereNull('end_date')->orWhere('end_date', '>=', now()));
        })->each(
            fn (Movie $m) => $sitemap->add(Url::create("/cinema/films/{$m->slug}")->setLastModificationDate($m->updated_at)->setPriority(0.6))
        );

        News::published()->each(
            fn (News $n) => $sitemap->add(Url::create("/actualites/{$n->slug}")->setLastModificationDate($n->updated_at)->setPriority(0.5))
        );

        Classified::where('status', 'published')->each(
            fn (Classified $c) => $sitemap->add(Url::create("/annonces/{$c->slug}")->setLastModificationDate($c->updated_at)->setPriority(0.4))
        );

        $sitemap->writeToFile(public_path('sitemap.xml'));

        $this->info('sitemap.xml régénéré ('.count($sitemap->getTags()).' URLs).');

        return self::SUCCESS;
    }
}
