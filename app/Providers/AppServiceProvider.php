<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Page;
use App\Models\Slider;
use App\Mail\Transport\BrevoApiTransport;
use App\Observers\CloudflarePurgeObserver;
use App\Observers\GoogleIndexingObserver;
use App\Observers\NewsletterDraftObserver;
use App\Observers\RegeneratesSitemapObserver;
use App\Services\Cache\CloudflareCachePurger;
use App\Services\Seo\GoogleIndexingService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
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
     * Modèles réellement présents dans `public/sitemap.xml` (demande client,
     * TECHNICAL_DOCUMENTATION.md §24 — "sitemap automatique après CRUD dans
     * l'admin") — voir `GenerateSitemap` pour la liste exacte des entités
     * qu'il parcourt. `Movie`/`Cinema` INCLUS ici (contrairement à
     * GOOGLE_INDEXING_MODELS) : `App\Jobs\RegenerateSitemap` est
     * auto-dédupliqué (`ShouldBeUnique`), le volume de `scrape:cinema` ne
     * pose donc pas le même problème de quota que l'API Google Indexing.
     */
    protected const SITEMAP_MODELS = [
        Category::class, Listing::class, EventCategory::class, Event::class,
        Cinema::class, Movie::class, News::class, Classified::class,
    ];

    /**
     * Modèles dont le passage au statut "published" doit générer un
     * BROUILLON de newsletter (demande client, 12/09/2026, voir
     * App\Observers\NewsletterDraftObserver et
     * TECHNICAL_DOCUMENTATION.md §36) — jamais d'envoi automatique, voir
     * docblock de l'observer.
     */
    protected const NEWSLETTER_DRAFT_MODELS = [
        Listing::class, News::class, Classified::class,
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
        // ⚠️ Bug réel trouvé et corrigé (15/09/2026, urgent, signalé par le
        // client : "les domaines dans le sitemap sont devenus localhost:8000
        // au lieu de toulouseweb.com"). `APP_URL` (.env) et le cache de
        // config (`bootstrap/cache/config.php`) étaient corrects en
        // production — mais `url()`/`route()`, appelés PAR `sitemap:generate`
        // (voir vendor/spatie/laravel-sitemap `url.blade.php`, `{{ url($tag->url) }}`),
        // se basent par défaut sur le HOST de la requête HTTP EN COURS
        // quand une requête est liée au conteneur — pas sur `config('app.url')`
        // — sauf en CLI pur (aucune requête liée), où `config('app.url')`
        // sert de repli et le résultat est donc correct. Or `sitemap:generate`
        // est aussi appelé DEPUIS UNE REQUÊTE HTTP par
        // App\Console\Commands\RunWebCron, lui-même déclenché via
        // App\Http\Controllers\WebCronController (`GET /webcron/{token}`,
        // voir TECHNICAL_DOCUMENTATION.md §27) : si cette requête arrive
        // avec un Host différent du domaine final (ancien domaine de
        // prévisualisation Infomaniak, requête directe par IP, health-check,
        // scan automatisé...), le sitemap régénéré à ce moment-là hérite de
        // CE host, pas de `toulouseweb.com` — confirmé en reproduisant :
        // `php artisan sitemap:generate` en SSH pur produit le bon domaine,
        // seul le déclenchement HTTP était en cause. `forceRootUrl()` élimine
        // structurellement ce risque : TOUTE génération d'URL absolue
        // (sitemap, mais aussi emails, redirections...) utilise désormais
        // `APP_URL` quel que soit le contexte d'appel (HTTP ou CLI),
        // ignorant le Host de la requête entrante — élimine au passage tout
        // risque de type "Host header injection" sur la génération d'URLs.
        URL::forceRootUrl(config('app.url'));
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        foreach (self::CLOUDFLARE_PURGE_MODELS as $model) {
            $model::observe(CloudflarePurgeObserver::class);
        }

        foreach (self::GOOGLE_INDEXING_MODELS as $model) {
            $model::observe(GoogleIndexingObserver::class);
        }

        foreach (self::SITEMAP_MODELS as $model) {
            $model::observe(RegeneratesSitemapObserver::class);
        }

        foreach (self::NEWSLETTER_DRAFT_MODELS as $model) {
            $model::observe(NewsletterDraftObserver::class);
        }

        // Envoi des newsletters via l'API Brevo (demande client, 14/09/2026,
        // voir config/mail.php mailer 'brevo' et TECHNICAL_DOCUMENTATION.md
        // §40) — transport personnalisé, pas de driver Laravel natif pour
        // Brevo.
        Mail::extend('brevo-api', fn (array $config) => new BrevoApiTransport(
            apiKey: $config['key'],
            senderEmail: $config['sender_email'] ?? null,
            senderName: $config['sender_name'] ?? null,
        ));

        // Un seul lot d'appels HTTP à la toute fin du processus (requête HTTP
        // ou commande artisan), pas un par modèle sauvegardé — voir docblock
        // de App\Observers\CloudflarePurgeObserver/GoogleIndexingObserver.
        $this->app->terminating(fn () => $this->app->make(CloudflareCachePurger::class)->flush());
        $this->app->terminating(fn () => $this->app->make(GoogleIndexingService::class)->flush());
    }
}
