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

    public function test_correct_token_runs_the_webcron_command_and_returns_200(): void
    {
        $this->get('/webcron/test-webcron-secret-value')->assertOk();
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
