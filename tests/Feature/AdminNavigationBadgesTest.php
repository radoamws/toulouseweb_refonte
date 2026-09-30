<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassifiedResource;
use App\Filament\Resources\EventResource;
use App\Filament\Resources\ListingResource;
use App\Filament\Resources\NewsResource;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demande client, 30/09/2026 (capture jointe montrant la pastille "3" sur
 * "Avis films") : la même pastille de compteur "à valider" doit apparaître
 * dans le menu admin pour Fiches (annuaire), Événements (agenda),
 * Actualités et Annonces. `ClassifiedResource::getNavigationBadge()`
 * existait déjà (voir MovieCommentModerationTest pour le même motif côté
 * Avis films) — étendu ici à `EventResource`/`ListingResource`/`NewsResource`,
 * absents jusqu'ici.
 */
class AdminNavigationBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_navigation_badge_shows_the_pending_count(): void
    {
        Event::create(['title' => 'Événement à valider', 'slug' => 'evenement-a-valider', 'status' => 'pending', 'start_date' => now()->addWeek()]);
        Event::create(['title' => 'Événement publié', 'slug' => 'evenement-publie', 'status' => 'published', 'start_date' => now()->addWeek()]);

        $this->assertSame('1', EventResource::getNavigationBadge());
    }

    public function test_event_navigation_badge_is_hidden_when_nothing_is_pending(): void
    {
        Event::create(['title' => 'Événement publié', 'slug' => 'evenement-publie-seul', 'status' => 'published', 'start_date' => now()->addWeek()]);

        $this->assertNull(EventResource::getNavigationBadge());
    }

    public function test_listing_navigation_badge_shows_the_pending_count(): void
    {
        Listing::create(['title' => 'Fiche à valider', 'slug' => 'fiche-a-valider', 'tier' => 'free', 'status' => 'pending']);
        Listing::create(['title' => 'Fiche publiée', 'slug' => 'fiche-publiee', 'tier' => 'free', 'status' => 'published']);

        $this->assertSame('1', ListingResource::getNavigationBadge());
    }

    public function test_news_navigation_badge_shows_the_pending_count(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-badge']);

        News::create(['title' => 'Article à valider', 'slug' => 'article-a-valider', 'category_id' => $category->id, 'status' => 'pending', 'body' => 'x']);
        News::create(['title' => 'Article publié', 'slug' => 'article-publie', 'category_id' => $category->id, 'status' => 'published', 'body' => 'x']);

        $this->assertSame('1', NewsResource::getNavigationBadge());
    }

    /** Déjà en place avant cette demande (voir docblock de classe) — vérifié ici pour mémoire. */
    public function test_classified_navigation_badge_shows_the_pending_count(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules-badge']);

        Classified::create(['title' => 'Annonce à valider', 'slug' => 'annonce-a-valider', 'category_id' => $category->id, 'status' => 'pending', 'description' => 'x', 'contact_email' => 'a@example.test']);
        Classified::create(['title' => 'Annonce publiée', 'slug' => 'annonce-publiee-badge', 'category_id' => $category->id, 'status' => 'published', 'description' => 'x', 'contact_email' => 'a@example.test']);

        $this->assertSame('1', ClassifiedResource::getNavigationBadge());
    }
}
