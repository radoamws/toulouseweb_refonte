<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsResource\Pages\CreateNews;
use App\Filament\Resources\NewsResource\Pages\EditNews;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Nouveaux champs "informations de l'événement" sur les actualités (demande
 * client, 03/09/2026, voir docblock de App\Models\News) — vérifie qu'ils
 * sont bien saisissables et persistés depuis l'admin, distincts de
 * `published_at`.
 */
class NewsResourceEventFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_create_news_with_event_fields(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Brocante du quartier',
                'slug' => 'brocante-du-quartier',
                'body' => '<p>Contenu</p>',
                'status' => 'published',
                'start_date' => '2026-10-10',
                'end_date' => '2026-10-12',
                'schedule' => 'Tous les jours de 9h à 18h',
                'address' => 'Place du Capitole, Toulouse',
                'price' => 'Entrée gratuite',
                'phone' => '0561000000',
                'email' => 'contact@brocante.test',
                'website' => 'https://brocante.example.test',
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $news = News::where('slug', 'brocante-du-quartier')->firstOrFail();
        $this->assertSame('2026-10-10', $news->start_date->toDateString());
        $this->assertSame('2026-10-12', $news->end_date->toDateString());
        $this->assertSame('Tous les jours de 9h à 18h', $news->schedule);
        $this->assertSame('Place du Capitole, Toulouse', $news->address);
        $this->assertSame('Entrée gratuite', $news->price);
        $this->assertSame('0561000000', $news->phone);
        $this->assertSame('contact@brocante.test', $news->email);
        $this->assertSame('https://brocante.example.test', $news->website);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $news->youtube_url);
    }

    /** website/youtube_url doivent rester des URLs valides (validation Filament ->url()). */
    public function test_website_and_youtube_url_are_validated_as_urls(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Actu invalide',
                'slug' => 'actu-invalide',
                'body' => '<p>x</p>',
                'status' => 'draft',
                'website' => 'pas une url',
            ])
            ->call('create')
            ->assertHasFormErrors(['website']);
    }

    /**
     * Demande client (04/09/2026, /admin/news/create) : "ne pas limiter la
     * longueur du texte car il y a des liens très long" — website/youtube_url
     * n'ont plus de ->maxLength() côté formulaire, et les colonnes ont été
     * élargies en `text` (migration widen_news_url_columns). Un lien de plus
     * de 255 caractères (paramètres UTM/tracking réalistes) doit être accepté
     * sans erreur ET conservé intégralement en base.
     */
    public function test_very_long_website_and_youtube_urls_are_accepted_without_truncation(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        $longWebsite = 'https://billetterie.example.test/reservation?'.str_repeat('utm_source=toulouseweb&utm_campaign=brocante-quartier&', 10);
        $longYoutube = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list='.str_repeat('a1B2c3D4e5', 30);

        $this->assertGreaterThan(255, strlen($longWebsite));
        $this->assertGreaterThan(255, strlen($longYoutube));

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Actu avec liens longs',
                'slug' => 'actu-avec-liens-longs',
                'body' => '<p>x</p>',
                'status' => 'draft',
                'website' => $longWebsite,
                'youtube_url' => $longYoutube,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $news = News::where('slug', 'actu-avec-liens-longs')->firstOrFail();
        $this->assertSame($longWebsite, $news->website);
        $this->assertSame($longYoutube, $news->youtube_url);
    }

    public function test_admin_can_edit_event_fields_on_existing_news(): void
    {
        $this->actingAs($this->authenticatedAdmin());

        $news = News::create([
            'title' => 'Actu à compléter', 'slug' => 'actu-a-completer', 'body' => '<p>x</p>', 'status' => 'draft',
        ]);

        Livewire::test(EditNews::class, ['record' => $news->id])
            ->fillForm(['end_date' => '2026-12-31'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-12-31', $news->fresh()->end_date->toDateString());
    }
}
