<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Déclencheur HTTP du scheduler (WebCron Infomaniak, voir
 * App\Http\Controllers\WebCronController, App\Console\Commands\RunWebCron
 * et TECHNICAL_DOCUMENTATION.md §27). WEBCRON_SECRET="test-webcron-secret-value"
 * fixé dans phpunit.xml pour ces tests uniquement.
 */
class WebCronTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(public_path('sitemap.xml'));
        parent::tearDown();
    }

    public function test_correct_token_runs_the_webcron_command_and_returns_200(): void
    {
        $this->get('/webcron/test-webcron-secret-value')->assertOk();
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (15/09/2026, urgent, signalé par le
     * client : sitemap en prod avec des URLs localhost:8000 au lieu de
     * toulouseweb.com). `sitemap:generate` (appelé ici via `webcron:run`,
     * lui-même déclenché par CETTE requête HTTP) utilise `url()` — qui,
     * SANS `URL::forceRootUrl()` (voir App\Providers\AppServiceProvider::boot()),
     * se base sur le Host de la requête HTTP en cours plutôt que sur
     * `config('app.url')`. Un Host quelconque (ancien domaine de
     * prévisualisation, requête directe par IP, voire un Host falsifié par
     * un client malveillant) contaminerait alors le sitemap régénéré. Ce
     * test envoie volontairement un Host différent de `config('app.url')`
     * pour vérifier que le sitemap généré ignore bien ce Host.
     */
    public function test_sitemap_generated_via_webcron_always_uses_app_url_never_the_request_host(): void
    {
        $this->get('/webcron/test-webcron-secret-value', ['Host' => 'attacker.example'])->assertOk();

        $sitemap = file_get_contents(public_path('sitemap.xml'));
        $this->assertStringContainsString((string) config('app.url'), $sitemap);
        $this->assertStringNotContainsString('attacker.example', $sitemap);
    }

    public function test_wrong_token_returns_404_not_403(): void
    {
        // 404 plutôt que 403 : ne révèle pas l'existence de la route à un
        // client qui devinerait un jeton au hasard (voir docblock du contrôleur).
        $this->get('/webcron/un-jeton-invente')->assertNotFound();
    }

    public function test_empty_token_segment_does_not_match_the_route(): void
    {
        $this->get('/webcron/')->assertNotFound();
    }

    /**
     * `public/robots.txt` est un fichier statique servi directement par le
     * serveur web en production (Apache), jamais par le routeur Laravel —
     * `$this->get('/robots.txt')` ne le traverse donc pas dans le client de
     * test (aucune route ne le sert, seul `RedirectFallbackController`
     * intercepterait et répondrait 404, sans rapport avec le vrai fichier).
     * On vérifie directement le contenu du fichier sur disque.
     */
    public function test_route_is_disallowed_in_robots_txt(): void
    {
        $this->assertStringContainsString('Disallow: /webcron', file_get_contents(public_path('robots.txt')));
    }

    /**
     * `redirects:audit` ne doit tourner que le dimanche (voir docblock de
     * RunWebCron — un déclenchement quotidien unique ne peut plus s'appuyer
     * sur `Schedule::weekly()` pour ce filtrage). `AuditRedirects::handle()`
     * affiche toujours "Aucune 404 récurrente..." quand `missed_redirects`
     * est vide (le cas ici, base de test fraîche) — ce message sert de
     * marqueur observable pour vérifier si la sous-commande a bien tourné.
     */
    /**
     * Demande client, 23/09/2026 : OpenAgenda republie RÉELLEMENT le même
     * événement sous un nouveau slug d'un jour sur l'autre (pas un artefact
     * ponctuel de migration) — le nettoyage des doublons doit donc tourner
     * à CHAQUE exécution du WebCron, juste après scrape:events, pas
     * seulement en ponctuel via SSH. Voir TECHNICAL_DOCUMENTATION.md §55.
     */
    public function test_webcron_run_cleans_up_agenda_duplicates_on_every_run(): void
    {
        Artisan::call('webcron:run');

        $output = Artisan::output();
        $this->assertStringContainsString('agenda-manual-dupes', $output);
        $this->assertStringContainsString('agenda-duplicate-events', $output);
    }

    public function test_redirects_audit_only_runs_on_sunday(): void
    {
        $this->travelTo(now()->next(\Carbon\Carbon::MONDAY));
        Artisan::call('webcron:run');
        $this->assertStringNotContainsString('Aucune 404 récurrente', Artisan::output());

        $this->travelTo(now()->next(\Carbon\Carbon::SUNDAY));
        Artisan::call('webcron:run');
        $this->assertStringContainsString('Aucune 404 récurrente', Artisan::output());
    }
}
