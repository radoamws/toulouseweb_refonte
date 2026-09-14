<?php

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Transport personnalisé App\Mail\Transport\BrevoApiTransport (demande
 * client, 14/09/2026, voir TECHNICAL_DOCUMENTATION.md §40) — utilise
 * `Http::fake()` (pas `Mail::fake()`, qui court-circuiterait le transport
 * lui-même avant qu'il ne construise sa requête) pour vérifier le payload
 * réellement envoyé à l'API Brevo et le comportement en cas de refus.
 *
 * Passe par `NewsletterSender::sendTest()` (pas un `Mail::to()->send()` nu)
 * — c'est le seul point d'entrée réel de l'app, et c'est justement LUI qui
 * garantit le bon mailer ('brevo') et le bon mode (synchrone) : voir le
 * docblock de NewsletterSender pour le bug réel (14/09/2026) que ce choix
 * corrige.
 */
class BrevoApiTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_newsletter_via_brevo_api_with_the_expected_payload(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<test@brevo>'], 201)]);

        $newsletter = Newsletter::create([
            'subject' => 'Objet de test', 'body_html' => '<p>Contenu</p>', 'status' => 'draft',
        ]);

        app(NewsletterSender::class)->sendTest($newsletter);

        $testRecipient = config('services.newsletter.test_recipients')[0];

        Http::assertSent(function ($request) use ($testRecipient) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', config('mail.mailers.brevo.key'))
                && $request['to'][0]['email'] === $testRecipient
                && $request['subject'] === 'Objet de test'
                && str_contains($request['htmlContent'], 'Contenu')
                && $request['sender']['email'] === config('mail.mailers.brevo.sender_email');
        });
    }

    public function test_a_rejection_from_the_brevo_api_throws_a_transport_exception(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Sender not verified'], 400)]);

        $newsletter = Newsletter::create([
            'subject' => 'Objet', 'body_html' => '<p>Contenu</p>', 'status' => 'draft',
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/Sender not verified/');

        app(NewsletterSender::class)->sendTest($newsletter);
    }

    /**
     * Garde-fou explicite contre la régression du 14/09/2026 : un mailable
     * `ShouldQueue` envoyé via `Mail::to(...)->send(...)` (sans passer par
     * `Mail::mailer('brevo')` ni `sendNow()`) est mis en FILE par Laravel
     * au lieu d'être réellement envoyé — aucune requête HTTP n'est alors
     * jamais faite, silencieusement. `sendTest()` doit donc produire une
     * requête HTTP immédiate, pas un job en attente.
     */
    public function test_send_test_makes_an_immediate_http_call_not_a_queued_job(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<test@brevo>'], 201)]);

        $newsletter = Newsletter::create([
            'subject' => 'Objet', 'body_html' => '<p>Contenu</p>', 'status' => 'draft',
        ]);

        app(NewsletterSender::class)->sendTest($newsletter);

        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
    }
}
