<?php

namespace Tests\Feature;

use App\Filament\Widgets\ClicksOverview;
use App\Models\ClickEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⚠️ Bug réel trouvé et corrigé (12/09/2026, demande client : "les stats ne
 * semblent pas fonctionner, j'ai navigué sur plusieurs pages et aucun clic
 * ne s'enregistre"). Cause : resources/js/track-click.js utilise
 * `navigator.sendBeacon()` en priorité (la quasi-totalité des navigateurs
 * modernes), une API qui NE PEUT PAS envoyer d'en-tête personnalisé — donc
 * jamais de X-CSRF-TOKEN. Vérifié en direct sur la vraie production : un
 * POST /track-click sans jeton répondait 419, et `sendBeacon` n'expose
 * jamais l'échec au JS (fire-and-forget) — chaque clic échouait
 * silencieusement. Voir bootstrap/app.php (`validateCsrfTokens(except:)`).
 *
 * Laravel désactive TOUJOURS la vérification CSRF pendant les tests
 * automatisés (`VerifyCsrfToken::runningUnitTests()`) — un `$this->post()`
 * "réussirait" même SANS cette exemption, ce test ne prouverait donc rien
 * en simulant juste une requête. On vérifie directement que la route est
 * bien dans la liste d'exemption CSRF (la config qui compte réellement en
 * production), pas le comportement d'une requête de test.
 */
class ClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_click_route_is_exempt_from_csrf_verification(): void
    {
        $middleware = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->assertContains('track-click', $middleware->getExcludedPaths());
    }

    public function test_track_click_records_a_click_event(): void
    {
        $this->postJson('/track-click', [
            'entity_type' => 'listing',
            'entity_id' => 42,
            'context' => 'homepage_card',
            'url' => 'https://toulouseweb.com/',
        ])->assertOk();

        $this->assertDatabaseHas('click_events', [
            'entity_type' => 'listing',
            'entity_id' => 42,
            'context' => 'homepage_card',
        ]);
    }

    public function test_track_click_requires_entity_type_and_id(): void
    {
        $this->postJson('/track-click', ['context' => 'homepage_card'])
            ->assertJsonValidationErrors(['entity_type', 'entity_id']);

        $this->assertSame(0, ClickEvent::count());
    }

    /**
     * Filtrage par date du dashboard (demande client, 12/09/2026 — voir
     * App\Filament\Pages\Dashboard et App\Filament\Widgets\Concerns\ResolvesDateFilters).
     * Teste directement la résolution de la plage (via réflexion sur les
     * méthodes protégées du trait) plutôt que de fouiller le HTML rendu :
     * plusieurs stats fixes du même widget peuvent légitimement afficher le
     * même nombre qu'un total filtré arbitraire, un `assertSee` sur une
     * simple valeur numérique ne prouverait donc rien de fiable.
     */
    public function test_clicks_overview_filter_dates_resolve_from_the_page_filters(): void
    {
        $widget = new ClicksOverview();
        $widget->filters = ['from' => '2026-01-05', 'to' => '2026-01-15'];

        $from = (new \ReflectionMethod($widget, 'filterFromDate'))->invoke($widget);
        $to = (new \ReflectionMethod($widget, 'filterToDate'))->invoke($widget);

        $this->assertSame('2026-01-05 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-01-15 23:59:59', $to->toDateTimeString());
    }

    /** Sans filtre choisi : repli sur les 30 derniers jours (comportement inchangé par rapport à avant ce correctif). */
    public function test_clicks_overview_filter_dates_default_to_last_30_days_when_unset(): void
    {
        $widget = new ClicksOverview();
        $widget->filters = null;

        $from = (new \ReflectionMethod($widget, 'filterFromDate'))->invoke($widget);
        $to = (new \ReflectionMethod($widget, 'filterToDate'))->invoke($widget);

        $this->assertSame(now()->subDays(29)->startOfDay()->toDateString(), $from->toDateString());
        $this->assertSame(now()->toDateString(), $to->toDateString());
    }

    /** Le dashboard admin se rend toujours correctement avec le nouveau formulaire de filtre (régression). */
    public function test_admin_dashboard_still_renders_with_the_new_filters_form(): void
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Clics — période filtrée');
    }
}
