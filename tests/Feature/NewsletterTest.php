<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsletterSubscriberResource\Pages\ListNewsletterSubscribers;
use App\Mail\NewsletterMail;
use App\Mail\SubmitterNotification;
use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Editor;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\Newsletter\EntityDigestSender;
use App\Services\Newsletter\NewsletterSender;
use App\Services\Newsletter\ScrapingDigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md
 * §36) : inscription homepage, inscription implicite via le formulaire de
 * contact, brouillons auto-générés (annuaire/actualités/annonces à la
 * publication, digest de scraping), et le garde-fou central demandé par le
 * client — "n'envoie à personne d'autre que moi avant mon GO"
 * (`services.newsletter.sending_enabled`, false par défaut, voir
 * NEWSLETTER_SENDING_ENABLED dans phpunit.xml).
 */
class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_form_subscribes_a_new_email(): void
    {
        $this->post('/newsletter/inscription', [
            'email' => 'visiteur@example.com',
            'name' => 'Jean Visiteur',
            'website' => '',
        ])->assertRedirect();

        $subscriber = NewsletterSubscriber::where('email', 'visiteur@example.com')->firstOrFail();
        $this->assertSame('active', $subscriber->status);
        $this->assertSame('homepage', $subscriber->source);
        $this->assertNotEmpty($subscriber->unsubscribe_token);
    }

    public function test_homepage_form_does_not_duplicate_an_already_subscribed_email(): void
    {
        $this->post('/newsletter/inscription', ['email' => 'deux-fois@example.com', 'website' => '']);
        $this->post('/newsletter/inscription', ['email' => 'deux-fois@example.com', 'website' => '']);

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_homepage_form_reactivates_an_unsubscribed_email(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'revient@example.com', 'status' => 'unsubscribed',
            'unsubscribed_at' => now(), 'source' => 'homepage',
        ]);

        $this->post('/newsletter/inscription', ['email' => 'revient@example.com', 'website' => '']);

        $this->assertSame('active', $subscriber->fresh()->status);
    }

    public function test_homepage_form_rejected_when_honeypot_filled(): void
    {
        $this->post('/newsletter/inscription', [
            'email' => 'bot@example.com',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_unsubscribe_link_deactivates_the_subscriber(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'depart@example.com']);

        $this->get('/newsletter/desinscription/'.$subscriber->unsubscribe_token)->assertOk();

        $subscriber->refresh();
        $this->assertSame('unsubscribed', $subscriber->status);
        $this->assertNotNull($subscriber->unsubscribed_at);
    }

    public function test_unsubscribe_with_an_unknown_token_is_a_404(): void
    {
        $this->get('/newsletter/desinscription/jeton-invalide')->assertNotFound();
    }

    /** Demande client verbatim (12/09/2026) : "toute personne s'inscrivant dans la page contact s'inscrit automatiquement aussi à la newsletter". */
    public function test_contact_form_submission_also_subscribes_to_the_newsletter(): void
    {
        $this->post('/contact', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'message' => 'Une question sur le site',
            'website' => '',
        ])->assertRedirect();

        $subscriber = NewsletterSubscriber::where('email', 'jean@example.com')->firstOrFail();
        $this->assertSame('active', $subscriber->status);
        $this->assertSame('contact_form', $subscriber->source);
        $this->assertSame('Jean Dupont', $subscriber->name);
    }

    /** Une inscription IMPLICITE (contact) ne doit jamais passer outre un désabonnement volontaire antérieur. */
    public function test_contact_form_does_not_resubscribe_someone_who_unsubscribed(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'parti@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now(),
        ]);

        $this->post('/contact', [
            'name' => 'Parti Ex',
            'email' => 'parti@example.com',
            'message' => 'Encore une question',
            'website' => '',
        ])->assertRedirect();

        $this->assertSame('unsubscribed', $subscriber->fresh()->status);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_publishing_a_listing_creates_a_draft_newsletter(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-nl', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Le Bon Resto', 'tier' => 'free', 'status' => 'pending',
        ]);
        $listing->categories()->attach($category->id);

        $this->assertDatabaseCount('newsletters', 0);

        $listing->update(['status' => 'published']);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('listing_published', $newsletter->trigger_type);
        $this->assertSame('draft', $newsletter->status);
        $this->assertSame(Listing::class, $newsletter->triggerable_type);
        $this->assertSame($listing->id, $newsletter->triggerable_id);
        $this->assertStringContainsString('Le Bon Resto', $newsletter->subject);
    }

    /** Un brouillon ne doit être créé qu'AU MOMENT de la publication, pas à chaque modification ultérieure d'une fiche déjà publiée. */
    public function test_editing_an_already_published_listing_does_not_create_a_second_draft(): void
    {
        $listing = Listing::create(['title' => 'Déjà publié', 'tier' => 'free', 'status' => 'published']);
        $this->assertDatabaseCount('newsletters', 1);

        $listing->update(['title' => 'Déjà publié (corrigé)']);

        $this->assertDatabaseCount('newsletters', 1);
    }

    public function test_publishing_a_news_article_creates_a_draft_newsletter(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-nl']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Une actu newsletter', 'slug' => 'une-actu-newsletter',
            'body' => 'Contenu', 'status' => 'pending',
        ]);

        $news->update(['status' => 'published', 'published_at' => now()]);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('news_published', $newsletter->trigger_type);
    }

    public function test_publishing_a_classified_creates_a_draft_newsletter(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules-nl', 'is_active' => true]);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Vélo à vendre newsletter',
            'description' => 'Bon état', 'status' => 'pending',
        ]);

        $classified->update(['status' => 'published']);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('classified_published', $newsletter->trigger_type);
    }

    /**
     * Demande client (12/11/2026) : "ceux qui ont fait des dépositions
     * d'annonces, annuaires, événements et actualités et qui ont été
     * validés à partir de cette refonte, les inclure automatiquement dans
     * les newsletters" — voir App\Observers\SubmitterSubscriptionObserver.
     */
    public function test_publishing_a_listing_subscribes_its_email_to_the_newsletter(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-sub', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Le Bon Resto Sub', 'tier' => 'free', 'status' => 'pending', 'email' => 'resto@example.com',
        ]);
        $listing->categories()->attach($category->id);

        $listing->update(['status' => 'published']);

        $subscriber = NewsletterSubscriber::where('email', 'resto@example.com')->firstOrFail();
        $this->assertSame('active', $subscriber->status);
        $this->assertSame('listing_submission', $subscriber->source);
    }

    public function test_publishing_a_listing_without_an_email_does_not_crash(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-no-email', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Resto sans email', 'tier' => 'free', 'status' => 'pending']);
        $listing->categories()->attach($category->id);

        $listing->update(['status' => 'published']);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    /** "À partir de cette refonte" exclut explicitement le contenu importé du legacy. */
    public function test_publishing_a_legacy_imported_listing_does_not_subscribe_its_email(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-legacy', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create([
            'title' => 'Resto legacy', 'tier' => 'free', 'status' => 'pending',
            'email' => 'legacy@example.com', 'legacy_id' => 987,
        ]);
        $listing->categories()->attach($category->id);

        $listing->update(['status' => 'published']);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_publishing_a_news_article_subscribes_its_submitter_email(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-sub']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Une actu sub', 'slug' => 'une-actu-sub',
            'body' => 'Contenu', 'status' => 'pending', 'submitter_email' => 'auteur@example.com',
        ]);

        $news->update(['status' => 'published', 'published_at' => now()]);

        $subscriber = NewsletterSubscriber::where('email', 'auteur@example.com')->firstOrFail();
        $this->assertSame('news_submission', $subscriber->source);
    }

    public function test_publishing_a_classified_subscribes_its_contact_email(): void
    {
        $category = ClassifiedCategory::create(['name' => 'Véhicules', 'slug' => 'vehicules-sub', 'is_active' => true]);
        $classified = Classified::create([
            'category_id' => $category->id, 'title' => 'Vélo à vendre sub',
            'description' => 'Bon état', 'status' => 'pending', 'contact_email' => 'vendeur@example.com',
        ]);

        $classified->update(['status' => 'published']);

        $subscriber = NewsletterSubscriber::where('email', 'vendeur@example.com')->firstOrFail();
        $this->assertSame('classified_submission', $subscriber->source);
    }

    public function test_publishing_a_user_submitted_event_subscribes_its_submitter_email(): void
    {
        $event = Event::create([
            'title' => 'Concert sub', 'status' => 'pending', 'source' => 'user_submitted',
            'start_date' => now()->addWeek(), 'submitter_email' => 'organisateur@example.com',
        ]);

        $event->update(['status' => 'published']);

        $subscriber = NewsletterSubscriber::where('email', 'organisateur@example.com')->firstOrFail();
        $this->assertSame('event_submission', $subscriber->source);
    }

    /** Les événements scrapés n'ont de toute façon aucun email, mais le filtre sur `source` est vérifié explicitement ici. */
    public function test_publishing_a_scraped_event_does_not_subscribe_anyone(): void
    {
        $event = Event::create([
            'title' => 'Concert scrapé', 'status' => 'pending', 'source' => 'scraped',
            'start_date' => now()->addWeek(), 'submitter_email' => 'ne-devrait-pas@example.com',
        ]);

        $event->update(['status' => 'published']);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_scraping_digest_builder_creates_a_draft_only_when_something_changed(): void
    {
        $this->assertNull(ScrapingDigestBuilder::build('Cinéma', 'cinema', ['created' => 0, 'updated' => 0]));
        $this->assertDatabaseCount('newsletters', 0);

        ScrapingDigestBuilder::build('Cinéma', 'cinema', ['created' => 3, 'updated' => 12]);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('scraping_digest', $newsletter->trigger_type);
        $this->assertSame('draft', $newsletter->status);
    }

    public function test_publishing_a_user_submitted_event_creates_a_draft_newsletter(): void
    {
        $event = Event::create([
            'title' => 'Concert digest', 'status' => 'pending', 'source' => 'user_submitted',
            'start_date' => now()->addWeek(), 'description' => 'Un super concert',
        ]);

        $event->update(['status' => 'published']);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('event_published', $newsletter->trigger_type);
        $this->assertSame('draft', $newsletter->status);
    }

    /** Plusieurs événements scrapés par jour — jamais un brouillon chacun (voir docblock de l'observer). */
    public function test_publishing_a_scraped_event_does_not_create_a_draft_newsletter(): void
    {
        $event = Event::create([
            'title' => 'Concert scrapé digest', 'status' => 'pending', 'source' => 'scraped',
            'start_date' => now()->addWeek(),
        ]);

        $event->update(['status' => 'published']);

        $this->assertDatabaseCount('newsletters', 0);
    }

    /**
     * Demande client (12/11/2026) : "faire un envoi automatique vers tous
     * les abonnés... avec un template professionnel sans mon intervention"
     * pour annonces/événements/annuaire/actus validés — voir
     * App\Services\Newsletter\EntityDigestSender.
     */
    public function test_entity_digest_sender_merges_pending_drafts_into_one_digest(): void
    {
        $category = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-digest', 'level' => 0, 'is_active' => true]);
        $listing = Listing::create(['title' => 'Resto digest', 'tier' => 'free', 'status' => 'published']);
        $listing->categories()->attach($category->id);

        $event = Event::create([
            'title' => 'Concert digest build', 'status' => 'published', 'source' => 'user_submitted',
            'start_date' => now()->addWeek(),
        ]);

        $this->assertDatabaseCount('newsletters', 2);

        $digest = app(EntityDigestSender::class)->build();

        $this->assertNotNull($digest);
        $this->assertSame('entity_digest', $digest->trigger_type);
        $this->assertSame('draft', $digest->status);
        $this->assertStringContainsString('Resto digest', $digest->body_html);
        $this->assertStringContainsString('Concert digest build', $digest->body_html);

        $this->assertDatabaseCount('newsletters', 3);
        $this->assertSame(0, Newsletter::where('status', 'draft')->where('trigger_type', '!=', 'entity_digest')->count());
        $this->assertSame(2, Newsletter::where('status', 'merged')->count());
    }

    public function test_entity_digest_sender_returns_null_when_nothing_pending(): void
    {
        $this->assertNull(app(EntityDigestSender::class)->build());
    }

    /** Une fiche dépubliée avant le passage du digest ne doit jamais bloquer les suivants. */
    public function test_entity_digest_sender_merges_but_skips_items_unpublished_since(): void
    {
        $listing = Listing::create(['title' => 'Resto dépublié', 'tier' => 'free', 'status' => 'published']);
        $listing->update(['status' => 'pending']);

        $this->assertNull(app(EntityDigestSender::class)->build());
        $this->assertSame('merged', Newsletter::firstOrFail()->status);
    }

    public function test_send_entity_digest_command_auto_sends_when_enabled(): void
    {
        config(['services.newsletter.sending_enabled' => true]);
        Mail::fake();
        NewsletterSubscriber::create(['email' => 'abonne-digest@example.com', 'status' => 'active']);
        Listing::create(['title' => 'Resto auto-envoi', 'tier' => 'free', 'status' => 'published']);

        Artisan::call('newsletter:send-entity-digest');

        $digest = Newsletter::where('trigger_type', 'entity_digest')->firstOrFail();
        $this->assertSame('sent', $digest->status);
        $this->assertSame(1, $digest->recipient_count);
        Mail::assertQueued(NewsletterMail::class, 1);
    }

    public function test_send_entity_digest_command_leaves_digest_as_draft_when_sending_disabled(): void
    {
        Mail::fake();
        Listing::create(['title' => 'Resto sans go', 'tier' => 'free', 'status' => 'published']);

        Artisan::call('newsletter:send-entity-digest');

        $digest = Newsletter::where('trigger_type', 'entity_digest')->firstOrFail();
        $this->assertSame('draft', $digest->status);
        Mail::assertNothingQueued();
    }

    /**
     * ⚠️ `Mail::fake()` route `Mail::to()->send($mailable)` vers son
     * registre "queued" (pas "sent") dès que le mailable implémente
     * `ShouldQueue` (voir `MailFake::sendMail()`) — c'est un choix du fake
     * de test, PAS le comportement du vrai `Mailer::send()` (celui-ci
     * envoie toujours en synchrone, `ShouldQueue` n'affecte que
     * `->queue()`, voir `App\Services\Newsletter\NewsletterSender::sendTest()`
     * qui appelle bien `->send()`, jamais `->queue()`). D'où
     * `assertQueued()` ici plutôt que `assertSent()`, qui échouerait à tort.
     */
    /**
     * `sendTest()` doit envoyer de façon SYNCHRONE (`sendNow()`, pas
     * `send()`) — bug réel trouvé le 14/09/2026 (voir docblock de
     * NewsletterSender) : `Mail::to(...)->send($mailable)` MET TOUJOURS EN
     * FILE un mailable `ShouldQueue`, même appelé via `->send()` — seul
     * `sendNow()` envoie réellement de façon synchrone. `Mail::fake()`
     * reproduit fidèlement cette distinction (`assertSent` vs
     * `assertQueued`), ce qui permet de vérifier ici que `sendTest()`
     * utilise bien la bonne méthode.
     */
    public function test_send_test_only_reaches_the_configured_test_recipients(): void
    {
        Mail::fake();
        NewsletterSubscriber::create(['email' => 'vrai-abonne@example.com']);
        $newsletter = Newsletter::create(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'status' => 'draft']);

        app(NewsletterSender::class)->sendTest($newsletter);

        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail) => $mail->hasTo('newsletter-test@example.com'));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail) => $mail->hasTo('vrai-abonne@example.com'));
        Mail::assertNothingQueued();
        $this->assertNotNull($newsletter->fresh()->test_sent_at);
    }

    /**
     * Garde-fou central demandé par le client (12/09/2026) : tant que
     * NEWSLETTER_SENDING_ENABLED est à false (défaut, voir phpunit.xml),
     * AUCUN envoi à un vrai abonné ne doit pouvoir se produire, même en
     * appelant directement le service.
     */
    public function test_send_to_all_is_refused_while_sending_is_not_enabled(): void
    {
        Mail::fake();
        NewsletterSubscriber::create(['email' => 'abonne@example.com']);
        $newsletter = Newsletter::create(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'status' => 'draft']);

        $this->expectException(\RuntimeException::class);

        try {
            app(NewsletterSender::class)->sendToAll($newsletter);
        } finally {
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
            $this->assertSame('draft', $newsletter->fresh()->status);
        }
    }

    public function test_send_to_all_queues_one_email_per_active_subscriber_once_enabled(): void
    {
        config(['services.newsletter.sending_enabled' => true]);
        Mail::fake();

        NewsletterSubscriber::create(['email' => 'actif1@example.com', 'status' => 'active']);
        NewsletterSubscriber::create(['email' => 'actif2@example.com', 'status' => 'active']);
        NewsletterSubscriber::create(['email' => 'parti@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $newsletter = Newsletter::create(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'status' => 'draft']);

        $count = app(NewsletterSender::class)->sendToAll($newsletter);

        $this->assertSame(2, $count);
        Mail::assertQueued(NewsletterMail::class, 2);
        Mail::assertNotQueued(NewsletterMail::class, fn (NewsletterMail $mail) => $mail->hasTo('parti@example.com'));
        $newsletter->refresh();
        $this->assertSame('sent', $newsletter->status);
        $this->assertSame(2, $newsletter->recipient_count);
        $this->assertNotNull($newsletter->sent_at);
    }

    /**
     * Retour client, 26/11/2026 : le bouton "Renvoyer un lien de connexion"
     * (voir ClassifiedEditSpaceTest et App\Filament\Concerns\ResendsLoginLink)
     * doit aussi être disponible depuis /admin/newsletter-subscribers — un
     * abonné newsletter n'a pas forcément de fiche (annonce/événement/
     * annuaire/actu) associée, donc pas d'action partagée possible ici, mais
     * le même mécanisme (Editor::resendLoginLink()) s'applique par email.
     */
    public function test_admin_can_resend_a_login_link_from_the_newsletter_subscribers_list(): void
    {
        Mail::fake();
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $subscriber = NewsletterSubscriber::create(['email' => 'abonne-nl@example.com', 'status' => 'active']);

        Livewire::test(ListNewsletterSubscribers::class)
            ->callTableAction('resendLoginLink', $subscriber);

        $editor = Editor::where('email', 'abonne-nl@example.com')->firstOrFail();
        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('abonne-nl@example.com')
            && str_contains($mail->actionUrl, route('editeur.inscription', $editor->invitation_token)));
    }
}
