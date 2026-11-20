<?php

namespace Tests\Feature;

use App\Models\Editor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug réel trouvé et corrigé (17/11/2026, signalé par le client : "quand je
 * valide le formulaire... rien ne se passe: sans message erreur ni
 * validation") — `App\Rules\Recaptcha` échoue bien côté serveur quand
 * `recaptcha_token` est absent (reCAPTCHA configuré, même en local, voir
 * .env), mais AUCUNE vue du site n'affichait d'erreur pour ce champ précis
 * (seuls les champs couverts par un `<x-ui.field-error name="...">` dédié
 * l'étaient) — l'utilisateur voyait juste la page se recharger à l'identique.
 * Corrigé avec `<x-ui.form-errors />` (résumé générique, $errors->all()),
 * posé sur TOUS les formulaires publics du site, pas seulement l'espace
 * éditeur — voir TECHNICAL_DOCUMENTATION.md.
 *
 * `phpunit.xml` désactive reCAPTCHA globalement (RECAPTCHA_SECRET_KEY vide)
 * — reconfiguré ici explicitement pour reproduire le même état que la prod
 * (clé renseignée), seul moyen de déclencher réellement cette validation.
 */
class FormErrorsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.recaptcha.secret_key' => 'une-fausse-cle-de-test']);
    }

    public function test_missing_recaptcha_token_shows_a_visible_error_on_the_editor_registration_page(): void
    {
        Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'un-token-de-test']);

        // recaptcha_token volontairement absent, comme un vrai navigateur
        // dont le script Google n'aurait pas répondu — suit la redirection
        // pour vérifier que la page rechargée affiche bien le message (plus
        // de "rien ne se passe").
        $response = $this->followingRedirects()->post('/mon-compte/inscription/un-token-de-test', [
            'name' => 'Jeanne',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertOk()->assertSee('obligatoire', false);
    }

    public function test_missing_recaptcha_token_shows_a_visible_error_on_the_login_page(): void
    {
        $response = $this->from(route('editeur.connexion'))->post('/mon-compte/connexion', [
            'email' => 'jeanne@example.test',
            'password' => 'motdepasse123',
            'website' => '',
        ]);

        $response->assertRedirect(route('editeur.connexion'));
        $response->assertSessionHasErrors('recaptcha_token');
    }

    public function test_the_contact_form_also_shows_a_visible_error_when_recaptcha_token_is_missing(): void
    {
        $response = $this->post('/contact', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'message' => 'Une question sur le site',
            'website' => '',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
    }
}
