<?php

namespace Tests\Feature;

use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\News;
use App\Services\Seo\GoogleIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Demande d'indexation automatique Google Search Console à chaque ajout/
 * modif/suppression de contenu (demande client, TECHNICAL_DOCUMENTATION.md
 * §20) — réécrit... en fait remplace un mécanisme qui n'existait pas côté
 * legacy (aucune trace d'appel à l'API Indexing/Search Console dans
 * old/backEnd). Voir docblock de App\Services\Seo\GoogleIndexingService pour
 * le choix de l'API Indexing (Search Console lui-même n'en expose aucune
 * pour la demande d'indexation).
 *
 * `config(['services.google_indexing...'])` active le mécanisme UNIQUEMENT
 * dans les tests qui en ont besoin — jamais par défaut (voir phpunit.xml),
 * pour ne jamais risquer un vrai appel HTTP sortant pendant la suite.
 */
class GoogleIndexingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Clé RSA de test jetable (générée hors-ligne via `openssl genrsa`,
     * jamais utilisée pour un vrai compte Google — Http::fake() intercepte
     * tout appel réseau). `openssl_sign()` (utilisé par le service réel)
     * n'a besoin que d'une clé PEM valide, PAS de `openssl_pkey_new()` —
     * volontairement pas généré à la volée ici : `openssl_pkey_new()`
     * nécessite un `openssl.cnf` que certains environnements PHP/Windows
     * (dont celui de développement de ce projet) ne trouvent pas, alors
     * que signer avec une clé PEM déjà fournie ne dépend pas de ce fichier.
     */
    protected const TEST_PRIVATE_KEY_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCMAjZSbrhr0Uzt
        E5CVQnJ9gcRj+StmWloP6Af4ZzqpumirCdLPSmIFFLwi4+znNCL31YgBnsgi2kID
        xQ3xNJJ+d9AdgpcogWYsrItt3je0ypf7GcISm9LiyQ4vjoP6zob7BiFKthgcDwDW
        V25mzy0/bryqHBPCE61ImpRV8rgt8dIHB9AEcP9xMlt8Ue0Yd9uZU/n6XHf84lMP
        nKsQR6uQDJCDJDgzf6MYoTmdCgqEhDq7puT2PvrRlj1FbVicVVqDk4jUG29FTZD2
        5skf+CnJuF0FEoO+bSGgT1WilZIs5w5ko746q2zyMBo3/gBOUzax5wtQ/5hgYszt
        oIUbulyLAgMBAAECggEAB+R4CEP4xKf5aV1/YQQ5GZ1SSaOm/Q+xOmq4e4BJbLGQ
        nobg1pHS3lNBtJXOQ2cYMYn2YmD/cNEgHfMVyusaKjCG3G/Oi/7MMhu0uggBpYD/
        xqy4PzEZij9BokMRnZA9SH+0w+/P6OtpMkWeq5z/AZYBSXWburnXottT6vE4mlGN
        TDeoOfrPvtmZ4nDW1FK+WeUMTVKMJTh3ZrdvH0G/HZZLD1MP0UJ2Z+7DN87DNqlx
        uDRw4fq611O/4pDG+LR9whVy5a9q9GPulRsFapazYenEuCxbmdeRQFZtqCmxr6oo
        dpLIBmowDZAcEk9lgyAlKZ93JJjll15i7bbdMmOmAQKBgQDDK1goxVsmZSjlwLyS
        8G3gCJixON/kM9nfUsrncPkzpoTNkM5TZEraSQ0KHTy6lPrXz36viny1rCOWVno9
        dvO078fqlLN9RFp8dwf2ztGgFrPLhmJnF+3ELMCZQv8U8pu13dTe9LdLoI/ywx4M
        Yb920fS/CYJgYcyS/XcaPWxy6wKBgQC3pZITV7W25QcxC6o3+7FSnCMvF2FP8tgf
        +eD+B+h4Lh3yO06/JYb2bzTzgakR6nJMhfN4RkGMSyFwR7YFUAxiC4j48b9EVp5k
        CFoLsRnRR3L6r/Ne2pvdvDMvVTYDuvhn4wydACxymq2RK5IiUKJA6poaU+dkUwQp
        WyNzUY0U4QKBgHN41q0wr1BVM9BTq36on+mYTHc2dkk3YGWgP4qVreugTxys21Y9
        lYf3Bq8AQ2kFMjCzhHnpzwVR9rBBNAvfsCtSXw7sshGgeoT/jAe7sA0uwWvec6QZ
        ZUTXUZCcMf272OLOf972HOiy89gnF0UuJDDx4gORZcEOvBIPwwMUanDHAoGBAIBb
        WyPl0/5HSaWAD7MdWizxMK5DWyK0C1ceIaGsCVGmegvKZBm5swEfbRUddPwur0DJ
        BwjzofDauj5uAMzpzB3jDhNhdFvhZsdoBvfRCsh5deW9gQ61IOf0GJpmpmApGGIU
        EcbSTj6z2chzeao+TYmt75OjPUGjvG7jYn3BbChBAoGBAKyWWXJ1DDoM51l0Odxd
        X4wxkbrunYZe6FVe7NKnoHaiPe9zelJZsOKAlLGxpbUsjagJrHTFmoqLinwHHBT+
        DYz10oQSiAYdjzbYs6DU2aOAuYX/UKvuSoPF9aJGgEjyZywE/AArTNIOvaeU6uU0
        EB5Y/qCs1f5YSMophZaqpBck
        -----END PRIVATE KEY-----
        PEM;

    protected function fakeCredentialsBase64(): string
    {
        return base64_encode(json_encode([
            'client_email' => 'test-indexing@example-project.iam.gserviceaccount.com',
            'private_key' => self::TEST_PRIVATE_KEY_PEM,
        ]));
    }

    protected function enableIndexing(int $dailyQuota = 200): void
    {
        config([
            'services.google_indexing.enabled' => true,
            'services.google_indexing.credentials_json_base64' => $this->fakeCredentialsBase64(),
            'services.google_indexing.daily_quota' => $dailyQuota,
        ]);
    }

    protected function fakeGoogleOk(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'indexing.googleapis.com/*' => Http::response(['urlNotificationMetadata' => []], 200),
        ]);
    }

    public function test_indexing_is_disabled_by_default_even_with_credentials_configured(): void
    {
        config(['services.google_indexing.credentials_json_base64' => $this->fakeCredentialsBase64()]);
        $this->fakeGoogleOk();

        News::create(['title' => 'Actu', 'slug' => 'actu', 'body' => 'x', 'status' => 'published']);
        app(GoogleIndexingService::class)->flush();

        Http::assertNothingSent();
    }

    public function test_published_news_sends_url_updated(): void
    {
        $this->enableIndexing();
        $this->fakeGoogleOk();

        News::create(['title' => 'Actu publiée', 'slug' => 'actu-publiee', 'body' => 'x', 'status' => 'published']);
        app(GoogleIndexingService::class)->flush();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'indexing.googleapis.com')
                && $request->data()['type'] === 'URL_UPDATED'
                && $request->data()['url'] === route('actualites.bySlug', 'actu-publiee');
        });
    }

    public function test_draft_news_sends_url_deleted(): void
    {
        $this->enableIndexing();
        $this->fakeGoogleOk();

        News::create(['title' => 'Brouillon', 'slug' => 'brouillon', 'body' => 'x', 'status' => 'draft']);
        app(GoogleIndexingService::class)->flush();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'indexing.googleapis.com')
                && $request->data()['type'] === 'URL_DELETED'
                && $request->data()['url'] === route('actualites.bySlug', 'brouillon');
        });
    }

    public function test_deleting_a_published_event_sends_url_deleted(): void
    {
        $this->enableIndexing();

        $event = Event::create(['title' => 'Événement', 'slug' => 'evenement', 'status' => 'published', 'start_date' => now()->addDay()]);
        app(GoogleIndexingService::class)->flush();

        $this->fakeGoogleOk();
        $event->delete();
        app(GoogleIndexingService::class)->flush();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'indexing.googleapis.com')
                && $request->data()['type'] === 'URL_DELETED'
                && $request->data()['url'] === route('agenda.bySlug', 'evenement');
        });
    }

    /** Au-delà du quota journalier configuré, les URLs restantes sont ignorées (pas d'échec, pas de dépassement réel de l'API). */
    public function test_quota_is_enforced_and_excess_is_skipped(): void
    {
        $this->enableIndexing(dailyQuota: 1);
        $this->fakeGoogleOk();

        News::create(['title' => 'Première', 'slug' => 'premiere', 'body' => 'x', 'status' => 'published']);
        News::create(['title' => 'Seconde', 'slug' => 'seconde', 'body' => 'x', 'status' => 'published']);
        app(GoogleIndexingService::class)->flush();

        // 1 appel token + 1 appel publish (le quota=1 empêche le second publish).
        Http::assertSentCount(2);
    }

    /** Le jeton OAuth2 est mis en cache : deux flush() successifs (2 sauvegardes distinctes) ne redemandent pas de jeton tant qu'il reste valide. */
    public function test_access_token_is_cached_across_flushes(): void
    {
        $this->enableIndexing();
        $this->fakeGoogleOk();

        News::create(['title' => 'Une', 'slug' => 'une', 'body' => 'x', 'status' => 'published']);
        app(GoogleIndexingService::class)->flush();

        News::create(['title' => 'Deux', 'slug' => 'deux', 'body' => 'x', 'status' => 'published']);
        app(GoogleIndexingService::class)->flush();

        Http::assertSentCount(3); // 1 token + 2 publish, pas 2 token + 2 publish
    }

    /**
     * Preuve de bout en bout que `app()->terminating()` (voir
     * AppServiceProvider) déclenche bien le flush automatiquement après une
     * VRAIE requête HTTP — même mécanisme que
     * CloudflareCachePurgeTest::test_public_listing_submission_triggers_purge_automatically_via_terminating.
     */
    public function test_public_classified_submission_triggers_indexing_request_automatically(): void
    {
        $this->enableIndexing();
        $this->fakeGoogleOk();

        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);

        $this->post('/annonces', [
            'category_id' => $category->id,
            'title' => 'Citadine à vendre',
            'description' => 'Très bon état.',
            'contact_email' => 'vendeur@example.test',
            'website' => '',
        ])->assertRedirect();

        // Statut "pending" (jamais publié directement, brief §8) -> URL_DELETED,
        // mais prouve que le cycle complet (Observer -> queue -> terminating -> flush -> HTTP) fonctionne.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'indexing.googleapis.com'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
