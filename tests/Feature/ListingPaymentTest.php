<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Services\Payments\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Tests\TestCase;

/**
 * Essai gratuit temporaire + paiement Stripe pour l'annuaire (demande
 * client, 24/11/2026, voir Listing::TRIAL_MONTHS/hasPremiumFeatures(),
 * App\Services\Payments\StripePaymentService et
 * TECHNICAL_DOCUMENTATION.md §83).
 *
 * `StripePaymentService` est une classe concrète (pas d'interface) —
 * `$this->mock()` la remplace dans le conteneur par résolution normale
 * (type-hint sur les méthodes des contrôleurs), aucun appel réseau Stripe
 * réel n'est jamais exécuté dans cette suite.
 */
class ListingPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeListing(array $overrides = []): Listing
    {
        return Listing::create(array_merge([
            'title' => 'Boulangerie du quartier',
            'slug' => 'boulangerie-paiement-'.uniqid(),
            'tier' => 'free',
            'status' => 'published',
            'submitter_email' => 'proprietaire@example.test',
        ], $overrides));
    }

    public function test_a_new_listing_gets_a_three_month_trial_with_premium_features(): void
    {
        $listing = $this->makeListing();

        $this->assertNotNull($listing->premium_until);
        $this->assertTrue($listing->premium_until->isFuture());
        $this->assertTrue($listing->premium_until->diffInDays(now()->addMonths(3)) < 1);
        $this->assertTrue($listing->hasPremiumFeatures());
        $this->assertTrue($listing->isTrialing());
    }

    public function test_a_free_listing_loses_premium_features_once_its_trial_has_expired(): void
    {
        $listing = $this->makeListing(['premium_until' => now()->subDay()]);

        $this->assertFalse($listing->hasPremiumFeatures());
        $this->assertFalse($listing->isTrialing());
    }

    public function test_a_paid_listing_with_an_active_subscription_keeps_premium_features_and_is_paid(): void
    {
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->addMonths(6)]);

        $this->assertTrue($listing->hasPremiumFeatures());
        $this->assertTrue($listing->isPaid());
        $this->assertFalse($listing->isLapsedPaid());
    }

    /** Rectification client, 25/11/2026 : la fiche payante n'est plus acquise définitivement — passé l'échéance sans renouvellement, elle perd ses options comme une fiche gratuite dont l'essai a expiré. */
    public function test_a_paid_listing_loses_premium_features_once_its_subscription_has_lapsed(): void
    {
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->subYear()]);

        $this->assertFalse($listing->hasPremiumFeatures());
        $this->assertFalse($listing->isPaid());
        $this->assertTrue($listing->isLapsedPaid());
    }

    public function test_paid_at_and_a_one_year_premium_until_are_set_automatically_the_first_time_tier_becomes_paid(): void
    {
        $listing = $this->makeListing();
        $this->assertNull($listing->paid_at);

        $listing->update(['tier' => 'paid']);
        $listing->refresh();

        $this->assertNotNull($listing->paid_at);
        $this->assertTrue($listing->premium_until->diffInDays(now()->addYear()) < 1);
    }

    public function test_listing_show_page_displays_the_logo_during_the_trial_even_for_a_free_tier(): void
    {
        $listing = $this->makeListing();
        $listing->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection('logo');

        $response = $this->get('/annuaire/fiche/'.$listing->slug)->assertOk();
        $response->assertSee($listing->getFirstMediaUrl('logo'), false);
    }

    public function test_listing_show_page_hides_the_logo_once_the_trial_has_expired_for_a_free_tier(): void
    {
        $listing = $this->makeListing(['premium_until' => now()->subDay()]);
        $listing->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection('logo');

        $response = $this->get('/annuaire/fiche/'.$listing->slug)->assertOk();
        $response->assertDontSee($listing->getFirstMediaUrl('logo'), false);
    }

    /** Couvre un vrai bug trouvé en écrivant cette fonctionnalité : `isoFormat()` (Symfony) utilisé initialement au lieu de `translatedFormat()` (convention Carbon/Laravel déjà utilisée ailleurs dans ce projet, voir Event::event_date_range) — la locale `fr` n'étant jamais explicitement configurée côté Carbon dans cette app, seule `translatedFormat()` affiche réellement le mois en français ici. */
    public function test_edit_space_page_renders_the_trial_countdown_without_error(): void
    {
        $listing = $this->makeListing();

        $response = $this->get(route('annuaire.editSpace', $listing->edit_token))->assertOk();
        $response->assertSee($listing->premium_until->translatedFormat('d F Y'));
    }

    public function test_edit_space_page_renders_the_paid_confirmation_without_error(): void
    {
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->addMonths(6)]);

        $this->get(route('annuaire.editSpace', $listing->edit_token))
            ->assertOk()
            ->assertSee('merci pour votre confiance', false)
            ->assertSee($listing->premium_until->translatedFormat('d F Y'));
    }

    public function test_edit_space_page_renders_the_lapsed_subscription_message_without_error(): void
    {
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->subDay()]);

        $this->get(route('annuaire.editSpace', $listing->edit_token))
            ->assertOk()
            ->assertSee('arrivé à échéance', false);
    }

    public function test_checkout_shows_a_fallback_message_when_stripe_is_not_enabled(): void
    {
        config(['services.stripe.enabled' => false]);
        $listing = $this->makeListing();

        $response = $this->post(route('annuaire.listings.checkout', $listing->edit_token));

        $response->assertRedirect(route('annuaire.editSpace', $listing->edit_token));
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, "n'est pas encore activé"));
    }

    public function test_checkout_redirects_to_stripe_when_enabled(): void
    {
        config(['services.stripe.enabled' => true]);
        $listing = $this->makeListing();

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSessionUrl')
                ->once()
                ->andReturn('https://checkout.stripe.com/fake-session');
        });

        $response = $this->post(route('annuaire.listings.checkout', $listing->edit_token));

        $response->assertRedirect('https://checkout.stripe.com/fake-session');
    }

    /** Rectification client, 25/11/2026 : l'abonnement étant annuel, payer alors que la fiche est déjà payante est un renouvellement anticipé légitime — jamais refusé. */
    public function test_checkout_is_still_allowed_for_an_already_paid_listing_to_let_it_renew_early(): void
    {
        config(['services.stripe.enabled' => true]);
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->addMonths(6)]);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSessionUrl')
                ->once()
                ->andReturn('https://checkout.stripe.com/fake-renewal-session');
        });

        $response = $this->post(route('annuaire.listings.checkout', $listing->edit_token));

        $response->assertRedirect('https://checkout.stripe.com/fake-renewal-session');
    }

    public function test_submitting_the_public_form_with_paid_tier_redirects_straight_to_checkout_when_stripe_is_enabled(): void
    {
        config(['services.stripe.enabled' => true]);
        $category = Category::create(['name' => 'Commerces', 'slug' => 'commerces-paiement-immediat', 'level' => 0, 'is_active' => true]);

        $response = $this->post(route('annuaire.store'), [
            'category_id' => $category->id,
            'tier' => 'paid',
            'title' => 'Boulangerie du quartier',
            'email' => 'proprietaire@example.test',
            'url_verification' => '',
        ]);

        $listing = Listing::where('title', 'Boulangerie du quartier')->firstOrFail();
        $response->assertRedirect(route('annuaire.listings.checkout', $listing->edit_token));
    }

    public function test_submitting_the_public_form_with_free_tier_keeps_the_usual_confirmation_message(): void
    {
        config(['services.stripe.enabled' => true]);
        $category = Category::create(['name' => 'Commerces', 'slug' => 'commerces-gratuit-immediat', 'level' => 0, 'is_active' => true]);

        $response = $this->post(route('annuaire.store'), [
            'category_id' => $category->id,
            'tier' => 'free',
            'title' => 'Boulangerie gratuite',
            'email' => 'proprietaire-gratuit@example.test',
            'url_verification' => '',
        ]);

        $response->assertRedirect(route('annuaire.index'));
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')
                ->once()
                ->andThrow(new SignatureVerificationException('bad signature'));
        });

        $response = $this->postJson('/stripe/webhook', [], ['Stripe-Signature' => 'invalid']);

        $response->assertStatus(400);
    }

    public function test_webhook_marks_the_listing_as_paid_on_checkout_session_completed(): void
    {
        $listing = $this->makeListing();

        $session = Session::constructFrom(['id' => 'cs_test_123', 'metadata' => ['listing_id' => (string) $listing->id]]);
        $event = Event::constructFrom(['id' => 'evt_123', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);

        $this->mock(StripePaymentService::class, function ($mock) use ($event, $listing) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturn($event);
            $mock->shouldReceive('listingIdFromSession')->once()->andReturn($listing->id);
            $mock->shouldReceive('markListingAsPaid')->once()->with($listing->id, 'cs_test_123');
        });

        $response = $this->postJson('/stripe/webhook', [], ['Stripe-Signature' => 'valid']);

        $response->assertStatus(200);
    }

    public function test_mark_listing_as_paid_flips_tier_and_sets_a_one_year_premium_until(): void
    {
        $listing = $this->makeListing();
        $service = app(StripePaymentService::class);

        $service->markListingAsPaid($listing->id, 'cs_session_1');
        $listing->refresh();

        $this->assertSame('paid', $listing->tier);
        $this->assertTrue($listing->premium_until->diffInDays(now()->addYear()) < 1);
    }

    /** Un même évènement webhook Stripe peut être renvoyé plusieurs fois pour la même session — rejouer ne doit PAS prolonger `premium_until` une deuxième fois. */
    public function test_mark_listing_as_paid_is_idempotent_for_the_same_stripe_session(): void
    {
        $listing = $this->makeListing();
        $service = app(StripePaymentService::class);

        $service->markListingAsPaid($listing->id, 'cs_session_1');
        $listing->refresh();
        $firstPaidAt = $listing->paid_at;
        $firstPremiumUntil = $listing->premium_until;

        $service->markListingAsPaid($listing->id, 'cs_session_1');
        $listing->refresh();

        $this->assertSame($firstPaidAt->toDateTimeString(), $listing->paid_at->toDateTimeString());
        $this->assertSame($firstPremiumUntil->toDateTimeString(), $listing->premium_until->toDateTimeString());
    }

    /** Un renouvellement l'année suivante (session Stripe DIFFÉRENTE) doit prolonger `premium_until` d'une année de plus à partir de l'échéance actuelle, pas la remplacer. */
    public function test_mark_listing_as_paid_extends_premium_until_from_current_expiry_on_renewal(): void
    {
        $listing = $this->makeListing(['tier' => 'paid', 'premium_until' => now()->addMonths(2), 'paid_at' => now()->subYear()]);
        $service = app(StripePaymentService::class);
        $expectedExpiry = $listing->premium_until->copy()->addYear();

        $service->markListingAsPaid($listing->id, 'cs_session_2');
        $listing->refresh();

        $this->assertSame($expectedExpiry->toDateTimeString(), $listing->premium_until->toDateTimeString());
    }
}
