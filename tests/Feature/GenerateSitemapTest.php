<?php

namespace Tests\Feature;

use App\Jobs\RegenerateSitemap;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `php artisan sitemap:generate` — aucun test n'existait avant l'audit
 * SEO/perf final du 07/09/2026 (contrairement à `migrate:*`/`images:*`,
 * cette commande ne dépend que de la base applicative, donc entièrement
 * testable en sandbox).
 *
 * ⚠️ Bug réel trouvé et corrigé pendant l'écriture de ce test (voir
 * docblock de `Screening::scopeCurrentlyValid()`) : le filtre "film
 * actuellement à l'affiche" dupliquait la logique de
 * `CinemaController::currentlyValid()` SANS son correctif DATE-vs-DATETIME
 * — comparait `end_date >= now()` (Carbon complet) au lieu de
 * `now()->toDateString()`. Le test ci-dessous (séance se terminant
 * exactement aujourd'hui) aurait échoué avant ce correctif.
 */
class GenerateSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(public_path('sitemap.xml'));
        parent::tearDown();
    }

    public function test_includes_static_pages(): void
    {
        $this->artisan('sitemap:generate')->assertSuccessful();

        $xml = file_get_contents(public_path('sitemap.xml'));
        foreach (['/annuaire', '/agenda', '/cinema', '/actualites', '/annonces', '/contact'] as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml);
        }
    }

    public function test_includes_published_content(): void
    {
        News::create(['title' => 'Une actu', 'slug' => 'une-actu', 'body' => 'x', 'status' => 'published']);
        $event = Event::create(['title' => 'Un événement', 'slug' => 'un-evenement', 'status' => 'published', 'start_date' => now()->addDay()]);
        $listing = Listing::create(['title' => 'Un resto', 'slug' => 'un-resto', 'tier' => 'free', 'status' => 'published']);
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        Classified::create(['category_id' => $category->id, 'title' => 'Une annonce', 'slug' => 'une-annonce', 'description' => 'x', 'status' => 'published']);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $xml = file_get_contents(public_path('sitemap.xml'));
        $this->assertStringContainsString(url('/actualites/une-actu'), $xml);
        $this->assertStringContainsString(url('/agenda/un-evenement'), $xml);
        $this->assertStringContainsString(url('/annuaire/fiche/un-resto'), $xml);
        $this->assertStringContainsString(url('/annonces/une-annonce'), $xml);
    }

    public function test_excludes_draft_and_expired_content(): void
    {
        News::create(['title' => 'Brouillon', 'slug' => 'brouillon', 'body' => 'x', 'status' => 'draft']);
        Event::create(['title' => 'Événement passé', 'slug' => 'evenement-passe', 'status' => 'published', 'start_date' => now()->subMonth(), 'end_date' => now()->subWeek()]);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $xml = file_get_contents(public_path('sitemap.xml'));
        $this->assertStringNotContainsString('brouillon', $xml);
        $this->assertStringNotContainsString('evenement-passe', $xml);
    }

    /**
     * Verrouille le correctif : une séance se terminant exactement
     * aujourd'hui (comparaison de dates pures, pas d'heure) doit garder le
     * film dans le sitemap toute la journée — même piège que
     * `CinemaController::currentlyValid()`, voir docblock de
     * `Screening::scopeCurrentlyValid()`.
     */
    public function test_movie_with_screening_ending_today_is_included(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 9, 7, 23, 0));

        try {
            $cinema = Cinema::create(['name' => 'Un cinéma', 'slug' => 'un-cinema']);
            $movie = Movie::create(['title' => 'Un film', 'slug' => 'un-film']);
            Screening::create([
                'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
                'start_date' => now()->subWeek(), 'end_date' => now(), // se termine aujourd'hui
            ]);

            $this->artisan('sitemap:generate')->assertSuccessful();

            $xml = file_get_contents(public_path('sitemap.xml'));
            $this->assertStringContainsString(url('/cinema/films/un-film'), $xml);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_movie_with_only_past_screenings_is_excluded(): void
    {
        $cinema = Cinema::create(['name' => 'Un cinéma', 'slug' => 'un-cinema-2']);
        $movie = Movie::create(['title' => 'Vieux film', 'slug' => 'vieux-film']);
        Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->subWeek(),
        ]);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $xml = file_get_contents(public_path('sitemap.xml'));
        $this->assertStringNotContainsString('vieux-film', $xml);
    }

    /**
     * "Sitemap automatique après CRUD dans l'admin" (demande client,
     * TECHNICAL_DOCUMENTATION.md §24) — vérifie le déclenchement, pas
     * seulement la commande sitemap:generate elle-même (déjà couverte
     * ci-dessus). Une régénération complète par sauvegarde serait coûteuse
     * (des dizaines de milliers de lignes) : traité en file d'attente
     * (App\Jobs\RegenerateSitemap, ShouldBeUnique) plutôt qu'en ligne.
     */
    public function test_saving_a_sitemap_model_dispatches_the_regeneration_job(): void
    {
        Queue::fake();

        News::create(['title' => 'Actu', 'slug' => 'actu-sitemap-job', 'body' => 'x', 'status' => 'published']);

        Queue::assertPushed(RegenerateSitemap::class);
    }

    /**
     * `Queue::fake()` applique bien lui-même la déduplication `ShouldBeUnique`
     * (vérifié empiriquement — pas juste supposé) : malgré 3 sauvegardes
     * distinctes (2 News + 1 Event), un seul job est réellement poussé.
     * C'est exactement le comportement recherché : `sitemap:generate`
     * reconstruit toujours le fichier entier, une régénération par
     * sauvegarde serait un gaspillage pur.
     */
    public function test_multiple_saves_only_dispatch_one_unique_job(): void
    {
        Queue::fake();

        News::create(['title' => 'Une', 'slug' => 'une-sitemap', 'body' => 'x', 'status' => 'published']);
        News::create(['title' => 'Deux', 'slug' => 'deux-sitemap', 'body' => 'x', 'status' => 'published']);
        Event::create(['title' => 'Trois', 'slug' => 'trois-sitemap', 'status' => 'published', 'start_date' => now()->addDay()]);

        Queue::assertPushed(RegenerateSitemap::class, 1);
    }

    /** Supprimer un contenu du sitemap doit aussi déclencher une régénération. */
    public function test_deleting_a_sitemap_model_dispatches_the_regeneration_job(): void
    {
        $news = News::create(['title' => 'Actu à supprimer', 'slug' => 'actu-a-supprimer-sitemap', 'body' => 'x', 'status' => 'published']);

        Queue::fake();
        $news->delete();

        Queue::assertPushed(RegenerateSitemap::class);
    }
}
