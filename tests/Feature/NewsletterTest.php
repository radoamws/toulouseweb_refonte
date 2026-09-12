<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Category;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Listing;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterSender;
use App\Services\Newsletter\ScrapingDigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_scraping_digest_builder_creates_a_draft_only_when_something_changed(): void
    {
        $this->assertNull(ScrapingDigestBuilder::build('Cinéma', 'cinema', ['created' => 0, 'updated' => 0]));
        $this->assertDatabaseCount('newsletters', 0);

        ScrapingDigestBuilder::build('Cinéma', 'cinema', ['created' => 3, 'updated' => 12]);

        $newsletter = Newsletter::firstOrFail();
        $this->assertSame('scraping_digest', $newsletter->trigger_type);
        $this->assertSame('draft', $newsletter->status);
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
    public function test_send_test_only_reaches_the_configured_test_recipients(): void
    {
        Mail::fake();
        NewsletterSubscriber::create(['email' => 'vrai-abonne@example.com']);
        $newsletter = Newsletter::create(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'status' => 'draft']);

        app(NewsletterSender::class)->sendTest($newsletter);

        Mail::assertQueued(NewsletterMail::class, fn (NewsletterMail $mail) => $mail->hasTo('newsletter-test@example.com'));
        Mail::assertNotQueued(NewsletterMail::class, fn (NewsletterMail $mail) => $mail->hasTo('vrai-abonne@example.com'));
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
}
