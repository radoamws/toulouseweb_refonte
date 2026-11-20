<?php

namespace Tests\Feature;

use App\Mail\SubmitterNotification;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Espace client, étape 4 (demande client, 16/11/2026) —
 * `espace-client:backfill-historique`, voir docblock de la commande et
 * TECHNICAL_DOCUMENTATION.md.
 */
class BackfillEditSpaceAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simule un dépôt créé AVANT le déploiement de l'espace client : les 3
     * modèles génèrent désormais `edit_token` dès la création (voir leurs
     * `booted()`), ce qu'un vrai historique n'a jamais eu — on l'efface
     * explicitement après coup pour reproduire cet état.
     */
    protected function asHistorical(Classified|Event|News $record): void
    {
        $record->update(['edit_token' => null]);
    }

    public function test_dry_run_reports_counts_without_touching_the_database_or_sending_mail(): void
    {
        Mail::fake();
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'x', 'contact_email' => 'ancien@example.test', 'status' => 'published',
        ]);
        $this->asHistorical($classified);

        Artisan::call('espace-client:backfill-historique');

        $this->assertStringContainsString('Annonces éligibles : 1', Artisan::output());
        $this->assertNull($classified->fresh()->edit_token);
        Mail::assertNothingSent();
    }

    public function test_send_backfills_classified_submitter_email_from_contact_email_and_emails_the_link(): void
    {
        Mail::fake();
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'x', 'contact_email' => 'ancien@example.test', 'status' => 'published',
        ]);
        $this->asHistorical($classified);

        Artisan::call('espace-client:backfill-historique', ['--send' => true]);

        $classified->refresh();
        $this->assertSame('ancien@example.test', $classified->submitter_email);
        $this->assertNotEmpty($classified->edit_token);

        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('ancien@example.test')
            && str_contains($mail->actionUrl, $classified->edit_token));
    }

    public function test_send_backfills_edit_token_for_events_and_news_that_already_have_a_submitter_email(): void
    {
        Mail::fake();
        $event = Event::create([
            'title' => 'Concert', 'slug' => 'concert', 'start_date' => now()->addWeek(),
            'submitter_email' => 'proposant@example.test', 'status' => 'published',
        ]);
        $newsCategory = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $newsCategory->id, 'title' => 'Brocante', 'slug' => 'brocante',
            'body' => 'x', 'submitter_email' => 'autre@example.test', 'status' => 'published',
        ]);
        $this->asHistorical($event);
        $this->asHistorical($news);

        Artisan::call('espace-client:backfill-historique', ['--send' => true]);

        $this->assertNotEmpty($event->fresh()->edit_token);
        $this->assertNotEmpty($news->fresh()->edit_token);
        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('proposant@example.test'));
        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('autre@example.test'));
    }

    public function test_listings_are_never_touched(): void
    {
        Mail::fake();
        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published', 'email' => 'contact@example.test']);

        Artisan::call('espace-client:backfill-historique', ['--send' => true]);

        $this->assertNull($listing->fresh()->edit_token);
        $this->assertNull($listing->fresh()->submitter_email);
    }

    public function test_running_send_twice_is_idempotent(): void
    {
        Mail::fake();
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Scooter 125', 'slug' => 'scooter-125',
            'description' => 'x', 'contact_email' => 'ancien@example.test', 'status' => 'published',
        ]);
        $this->asHistorical($classified);

        Artisan::call('espace-client:backfill-historique', ['--send' => true]);
        Artisan::call('espace-client:backfill-historique');

        $this->assertStringContainsString('Annonces éligibles : 0', Artisan::output());
    }
}
