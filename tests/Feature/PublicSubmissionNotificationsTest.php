<?php

namespace Tests\Feature;

use App\Mail\AdminNotification;
use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Notifications admin par email + upload d'image sécurisé sur les
 * formulaires publics (demande client, 09/09/2026 — voir
 * App\Support\AdminNotifier, App\Rules\GenuineImage,
 * App\Services\Uploads\ImageSanitizer et TECHNICAL_DOCUMENTATION.md §28).
 * `ADMIN_NOTIFICATION_EMAILS=admin-test@example.com` fixé dans phpunit.xml.
 */
class PublicSubmissionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_notifies_admin(): void
    {
        Mail::fake();

        $this->post('/contact', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'message' => 'Une question sur le site',
            'website' => '',
        ])->assertRedirect();

        Mail::assertSent(AdminNotification::class, fn (AdminNotification $mail) => $mail->hasTo('admin-test@example.com')
            && $mail->heading === 'Nouveau message de contact');
    }

    public function test_no_notification_is_sent_when_no_admin_email_is_configured(): void
    {
        config(['services.admin_notifications.emails' => []]);
        Mail::fake();

        $this->post('/contact', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'message' => 'Une question sur le site',
            'website' => '',
        ])->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_classified_submission_with_a_genuine_image_is_accepted_and_notifies_admin(): void
    {
        Storage::fake('public');
        Mail::fake();

        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules', 'is_active' => true]);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Vélo à vendre',
            'description' => 'Très bon état',
            'contact_email' => 'vendeur@example.com',
            'photo' => UploadedFile::fake()->image('velo.jpg', 200, 200),
            'website' => '',
        ])->assertRedirect(route('annonces.index'));

        $classified = Classified::firstOrFail();
        $this->assertSame('pending', $classified->status);
        $this->assertCount(1, $classified->getMedia('photos'));

        Mail::assertSent(AdminNotification::class, fn (AdminNotification $mail) => $mail->heading === 'Nouvelle annonce à valider');
    }

    /**
     * Le coeur de la demande client : *"vérifier que ce ne sont pas des
     * faux images (hack avec extension d'image)"* — un fichier qui déclare
     * un MIME "image/jpeg" mais dont le contenu réel n'est pas décodable
     * comme une image doit être rejeté, pas juste accepté sur la base de
     * l'extension/MIME déclarée.
     */
    public function test_classified_submission_rejects_a_file_disguised_as_an_image(): void
    {
        Storage::fake('public');
        Mail::fake();

        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules', 'is_active' => true]);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Vélo à vendre',
            'description' => 'Très bon état',
            'contact_email' => 'vendeur@example.com',
            'photo' => UploadedFile::fake()->create('malware.jpg', 10, 'image/jpeg'),
            'website' => '',
        ])->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('classifieds', 0);
        Mail::assertNothingSent();
    }

    public function test_event_submission_with_a_genuine_image_is_stored_and_sanitized(): void
    {
        Storage::fake('public');
        Mail::fake();

        $category = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Concert de jazz',
            'venue_name' => 'Salle des fêtes',
            'start_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            'image' => UploadedFile::fake()->image('concert.png', 300, 200),
            'website' => '',
        ])->assertRedirect(route('agenda.index'));

        $event = Event::firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertNotNull($event->image);
        Storage::disk('public')->assertExists($event->image);

        Mail::assertSent(AdminNotification::class, fn (AdminNotification $mail) => $mail->heading === 'Nouvel événement à valider');
    }

    public function test_event_submission_rejects_a_file_disguised_as_an_image(): void
    {
        Storage::fake('public');
        Mail::fake();

        $category = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts']);

        $this->post('/agenda/proposer', [
            'event_category_id' => $category->id,
            'title' => 'Concert de jazz',
            'venue_name' => 'Salle des fêtes',
            'start_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            'image' => UploadedFile::fake()->create('malware.png', 10, 'image/png'),
            'website' => '',
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('events', 0);
    }

    /** La formule (gratuite/payante) est un choix du visiteur, mais le statut reste TOUJOURS "pending" (brief §5, non contournable). */
    public function test_listing_submission_honors_the_chosen_tier_but_always_stays_pending(): void
    {
        Mail::fake();

        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'paid',
            'title' => 'Le Bon Restaurant',
            'description' => 'Une description complète',
            'website' => 'https://example.com',
            'url_verification' => '',
        ])->assertRedirect(route('annuaire.index'));

        $listing = Listing::firstOrFail();
        $this->assertSame('paid', $listing->tier);
        $this->assertSame('pending', $listing->status);

        Mail::assertSent(AdminNotification::class, fn (AdminNotification $mail) => $mail->heading === 'Nouvelle fiche annuaire à valider'
            && $mail->lines['Formule'] === 'Payante');
    }

    public function test_listing_submission_rejects_an_invalid_tier_value(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);

        $this->post('/annuaire/deposer', [
            'category_id' => $category->id,
            'tier' => 'gold', // valeur inventée, non acceptée
            'title' => 'Le Bon Restaurant',
            'url_verification' => '',
        ])->assertSessionHasErrors('tier');

        $this->assertDatabaseCount('listings', 0);
    }

    public function test_news_submission_page_and_store_create_a_pending_article_and_notify_admin(): void
    {
        Mail::fake();

        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);

        $this->get('/actualites/proposer')->assertOk();

        $this->post('/actualites/proposer', [
            'category_id' => $category->id,
            'title' => 'Une brocante ce week-end',
            'excerpt' => 'À ne pas manquer',
            'body' => 'Tous les détails de cette brocante...',
            'submitter_email' => 'proposant@example.com',
            'website' => '',
        ])->assertRedirect(route('actualites.index'));

        $news = News::firstOrFail();
        $this->assertSame('pending', $news->status);
        $this->assertSame('proposant@example.com', $news->submitter_email);

        Mail::assertSent(AdminNotification::class, fn (AdminNotification $mail) => $mail->heading === 'Nouvelle actualité à valider');
    }

    public function test_news_submission_honeypot_silently_rejects_bots(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);

        $this->post('/actualites/proposer', [
            'category_id' => $category->id,
            'title' => 'Spam',
            'body' => 'Spam',
            'submitter_email' => 'bot@example.com',
            'website' => 'http://spam.example', // honeypot rempli
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('news', 0);
    }
}
