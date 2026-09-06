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
use App\Observers\GoogleIndexingObserver;
use App\Services\Cache\CloudflareCachePurger;
use App\Services\Seo\GoogleIndexingService;
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
     * Modèles dont une sauvegarde/suppression doit déclencher une demande
     * d'indexation Google (demande client, TECHNICAL_DOCUMENTATION.md §20) —
     * chacun implémente App\Contracts\HasGoogleIndexingUrl. Délibérément SANS
     * Movie/Cinema (contrairement à CLOUDFLARE_PURGE_MODELS ci-dessus) :
     * `scrape:cinema` sauvegarde des centaines de Movie par exécution
     * quotidienne, ce qui épuiserait à lui seul le quota journalier de
     * l'API Indexing (200 requêtes/jour par défaut, UNE URL par appel —
     * contrairement à Cloudflare qui accepte des lots de 30) avant même
     * qu'un contenu éditorial n'ait sa chance d'être soumis le même jour.
     */
    protected const GOOGLE_INDEXING_MODELS = [
        News::class, Event::class, Listing::class, Classified::class,
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
        $this->app->singleton(GoogleIndexingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::CLOUDFLARE_PURGE_MODELS as $model) {
            $model::observe(CloudflarePurgeObserver::class);
        }

        foreach (self::GOOGLE_INDEXING_MODELS as $model) {
            $model::observe(GoogleIndexingObserver::class);
        }

        // Un seul lot d'appels HTTP à la toute fin du processus (requête HTTP
        // ou commande artisan), pas un par modèle sauvegardé — voir docblock
        // de App\Observers\CloudflarePurgeObserver/GoogleIndexingObserver.
        $this->app->terminating(fn () => $this->app->make(CloudflareCachePurger::class)->flush());
        $this->app->terminating(fn () => $this->app->make(GoogleIndexingService::class)->flush());
    }
}
