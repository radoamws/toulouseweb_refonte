<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `newsletter:sync-brevo-bounces` (demande client, 15/09/2026 : "si
 * possible de récupérer les contacts dans Brevo où les mails ne
 * fonctionnent plus" — voir TECHNICAL_DOCUMENTATION.md §44).
 */
class SyncBrevoBouncesTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_hard_bounced_and_blocked_subscribers_as_invalid(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/statistics/events*event=hardBounces*' => Http::response([
                'events' => [['email' => 'MORTE@example.com', 'event' => 'hardBounces']],
            ]),
            'api.brevo.com/v3/smtp/statistics/events*event=blocked*' => Http::response([
                'events' => [['email' => 'bloquee@example.com', 'event' => 'blocked']],
            ]),
        ]);

        $bounced = NewsletterSubscriber::create(['email' => 'morte@example.com', 'status' => 'active']);
        $blocked = NewsletterSubscriber::create(['email' => 'bloquee@example.com', 'status' => 'active']);
        $healthy = NewsletterSubscriber::create(['email' => 'vivante@example.com', 'status' => 'active']);

        $this->artisan('newsletter:sync-brevo-bounces')->assertSuccessful();

        $this->assertSame('invalid', $bounced->fresh()->status);
        $this->assertSame('invalid', $blocked->fresh()->status);
        $this->assertSame('active', $healthy->fresh()->status);
    }

    /** softBounces = échec TEMPORAIRE (boîte pleine, timeout...) — ne doit jamais invalider un abonné. */
    public function test_does_not_touch_soft_bounces(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['events' => []])]);

        $subscriber = NewsletterSubscriber::create(['email' => 'temporaire@example.com', 'status' => 'active']);

        $this->artisan('newsletter:sync-brevo-bounces')->assertSuccessful();

        $this->assertSame('active', $subscriber->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'event=softBounces'));
    }

    public function test_paginates_through_all_bounce_pages(): void
    {
        $firstPage = array_map(fn ($i) => ['email' => "bounce{$i}@example.com"], range(1, 500));
        $secondPage = [['email' => 'bounce501@example.com']];

        Http::fake([
            'api.brevo.com/v3/smtp/statistics/events*event=hardBounces*offset=0*' => Http::response(['events' => $firstPage]),
            'api.brevo.com/v3/smtp/statistics/events*event=hardBounces*offset=500*' => Http::response(['events' => $secondPage]),
            'api.brevo.com/v3/smtp/statistics/events*event=blocked*' => Http::response(['events' => []]),
        ]);

        NewsletterSubscriber::create(['email' => 'bounce501@example.com', 'status' => 'active']);

        $this->artisan('newsletter:sync-brevo-bounces')->assertSuccessful();

        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'bounce501@example.com', 'status' => 'invalid']);
        Http::assertSentCount(3); // 2 pages hardBounces (500 puis 1, qui arrête la pagination) + 1 page blocked (vide)
    }
}
