<?php

namespace App\Providers;

use App\Models\Cinema;
use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Page;
use App\Models\Slider;
use App\Observers\CloudflarePurgeObserver;
use App\Services\Cache\CloudflareCachePurger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Modèles de contenu public dont une sauvegarde/suppression doit purger
     * du cache Cloudflare (demande client, TECHNICAL_DOCUMENTATION.md §17) —
     * chacun implémente App\Contracts\HasCloudflarePurgeUrls.
     */
    protected const CLOUDFLARE_PURGE_MODELS = [
        News::class, Event::class, Listing::class, Classified::class,
        Movie::class, Cinema::class, Slider::class, Page::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton impératif : les URLs purgées doivent s'accumuler dans LA
        // MÊME instance tout au long du processus (chaque appel Observer +
        // le flush final via terminating()), pas dans des instances
        // transitoires indépendantes qui se videraient chacune de leur côté.
        $this->app->singleton(CloudflareCachePurger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::CLOUDFLARE_PURGE_MODELS as $model) {
            $model::observe(CloudflarePurgeObserver::class);
        }

        // Un seul lot d'appels HTTP à la toute fin du processus (requête HTTP
        // ou commande artisan), pas un par modèle sauvegardé — voir docblock
        // de App\Observers\CloudflarePurgeObserver.
        $this->app->terminating(fn () => $this->app->make(CloudflareCachePurger::class)->flush());
    }
}
