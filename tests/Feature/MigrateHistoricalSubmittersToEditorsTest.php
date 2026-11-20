<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use App\Models\Event;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `editeurs:migrer-historique` — comptes éditeur, point 1 de la "Grande
 * modification" (demande client, 17/11/2026). Voir docblock de la commande
 * et TECHNICAL_DOCUMENTATION.md.
 */
class MigrateHistoricalSubmittersToEditorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_touch_the_database(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter', 'slug' => 'scooter',
            'description' => 'x', 'contact_email' => 'ancien@example.test', 'status' => 'published',
        ]);

        Artisan::call('editeurs:migrer-historique');

        $this->assertStringContainsString('Emails distincts concernés : 1', Artisan::output());
        $this->assertNull($classified->fresh()->editor_id);
        $this->assertDatabaseCount('editors', 0);
    }

    public function test_commit_creates_one_editor_per_distinct_email_and_links_every_entity(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter', 'slug' => 'scooter',
            'description' => 'x', 'contact_email' => 'personne@example.test', 'status' => 'published',
        ]);
        $event = Event::create([
            'title' => 'Concert', 'slug' => 'concert', 'start_date' => now()->addWeek(),
            // Même personne, email identique mais casse différente — doit
            // fusionner sur le MÊME compte, pas en créer un second.
            'submitter_email' => 'Personne@Example.test', 'status' => 'published',
        ]);
        $newsCategory = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $newsCategory->id, 'title' => 'Brocante', 'slug' => 'brocante',
            'body' => 'x', 'submitter_email' => 'quelquun-dautre@example.test', 'status' => 'published',
        ]);

        Artisan::call('editeurs:migrer-historique', ['--commit' => true]);

        $this->assertDatabaseCount('editors', 2);

        $editor = Editor::where('email', 'personne@example.test')->firstOrFail();
        $this->assertSame($editor->id, $classified->fresh()->editor_id);
        $this->assertSame($editor->id, $event->fresh()->editor_id);
        $this->assertNotNull($editor->invitation_token);
        $this->assertNull($editor->password);

        $otherEditor = Editor::where('email', 'quelquun-dautre@example.test')->firstOrFail();
        $this->assertSame($otherEditor->id, $news->fresh()->editor_id);
    }

    public function test_running_commit_twice_is_idempotent(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter', 'slug' => 'scooter',
            'description' => 'x', 'contact_email' => 'personne@example.test', 'status' => 'published',
        ]);

        Artisan::call('editeurs:migrer-historique', ['--commit' => true]);
        Artisan::call('editeurs:migrer-historique', ['--commit' => true]);

        $this->assertDatabaseCount('editors', 1);
    }
}
