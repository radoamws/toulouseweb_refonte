<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Gestion des contenus expirés"/"événements passés" (brief §13, SEO/GEO) —
 * voir docblock de MarkExpiredContent : garde `status` cohérent côté admin,
 * n'affecte pas la visibilité publique (déjà filtrée par date ailleurs).
 */
class MarkExpiredContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_event_is_marked_expired(): void
    {
        $category = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre']);
        $past = Event::create([
            'title' => 'Événement passé', 'slug' => 'evenement-passe', 'status' => 'published',
            'start_date' => now()->subMonth(), 'end_date' => now()->subWeek(),
        ]);
        $past->categories()->attach($category);

        $upcoming = Event::create([
            'title' => 'Événement à venir', 'slug' => 'evenement-a-venir', 'status' => 'published',
            'start_date' => now()->addWeek(),
        ]);

        $this->artisan('content:mark-expired')->assertSuccessful();

        $this->assertSame('expired', $past->fresh()->status);
        $this->assertSame('published', $upcoming->fresh()->status);
    }

    public function test_event_without_end_date_expires_the_day_after_start_date(): void
    {
        $pastNoEnd = Event::create([
            'title' => 'Journée passée sans fin', 'slug' => 'journee-passee-sans-fin', 'status' => 'published',
            'start_date' => now()->subDays(2), 'end_date' => null,
        ]);
        $todayNoEnd = Event::create([
            'title' => "Aujourd'hui sans fin", 'slug' => 'aujourdhui-sans-fin', 'status' => 'published',
            'start_date' => now()->startOfDay(), 'end_date' => null,
        ]);

        $this->artisan('content:mark-expired');

        $this->assertSame('expired', $pastNoEnd->fresh()->status);
        $this->assertSame('published', $todayNoEnd->fresh()->status);
    }

    public function test_non_published_events_are_left_untouched(): void
    {
        $pending = Event::create([
            'title' => 'En attente', 'slug' => 'en-attente', 'status' => 'pending',
            'start_date' => now()->subMonth(),
        ]);

        $this->artisan('content:mark-expired');

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_classified_past_expires_at_is_marked_expired(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce expirée', 'slug' => 'annonce-expiree',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('content:mark-expired');

        $this->assertSame('expired', $classified->fresh()->status);
    }

    public function test_classified_without_expiry_date_is_left_untouched(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce sans expiration', 'slug' => 'annonce-sans-expiration',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        $this->artisan('content:mark-expired');

        $this->assertSame('published', $classified->fresh()->status);
    }
}
