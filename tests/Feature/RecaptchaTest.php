<?php

namespace Tests\Feature;

use App\Rules\Recaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * reCAPTCHA v3 (demande client, 19/09/2026 : "à mettre en place [...] pour
 * tous les formulaires dans le front") — voir App\Rules\Recaptcha.
 * `services.recaptcha.secret_key` n'est JAMAIS renseigné dans l'environnement
 * de test (phpunit.xml) : la règle doit rester un no-op fail-open partout
 * ailleurs dans la suite (annonces, agenda, actualités, annuaire, avis
 * film...), sans quoi TOUS les tests de soumission de formulaire échoueraient
 * d'un coup — ces tests-ci configurent explicitement une clé pour exercer le
 * comportement "activé".
 */
class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function validate(?string $token): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            ['recaptcha_token' => $token],
            ['recaptcha_token' => Recaptcha::rules('contact')]
        );
    }

    public function test_passes_when_not_configured_even_without_a_token(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $this->assertTrue($this->validate(null)->passes());
    }

    public function test_fails_when_configured_but_token_missing(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);

        $this->assertTrue($this->validate(null)->fails());
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (21/09/2026, vérifié en direct en prod
     * juste après activation) : un `Validator::make(['recaptcha_token' =>
     * null], ...)` (test ci-dessus) fait toujours tourner la règle (la clé
     * EXISTE, valeur null), alors qu'une vraie requête HTTP où le champ est
     * ENTIÈREMENT ABSENT du corps POST (aucune clé du tout — exactement ce
     * qu'envoie un bot basique, ou un `curl` sans le champ) est un cas
     * DIFFÉRENT : sans le `'required'` conditionnel de `Recaptcha::rules()`,
     * Laravel n'appelait même pas `validate()`. Voir docblock de la classe.
     */
    public function test_fails_when_the_field_is_entirely_absent_from_the_request(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);

        $validator = Validator::make([], ['recaptcha_token' => Recaptcha::rules('contact')]);

        $this->assertTrue($validator->fails());
    }

    public function test_does_not_require_the_field_when_not_configured(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $validator = Validator::make([], ['recaptcha_token' => Recaptcha::rules('contact')]);

        $this->assertTrue($validator->passes());
    }

    public function test_passes_with_a_valid_high_score_response(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret', 'services.recaptcha.min_score' => 0.5]);
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'action' => 'contact', 'score' => 0.9])]);

        $this->assertTrue($this->validate('a-real-token')->passes());
    }

    public function test_fails_when_google_reports_failure(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['www.google.com/*' => Http::response(['success' => false])]);

        $this->assertTrue($this->validate('bad-token')->fails());
    }

    public function test_fails_when_the_action_does_not_match(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        // Jeton généré pour un AUTRE formulaire (ex. "annonce") — rejeté ici.
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'action' => 'annonce', 'score' => 0.9])]);

        $this->assertTrue($this->validate('mismatched-action-token')->fails());
    }

    public function test_fails_when_the_score_is_below_the_minimum(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret', 'services.recaptcha.min_score' => 0.5]);
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'action' => 'contact', 'score' => 0.1])]);

        $this->assertTrue($this->validate('bot-like-token')->fails());
    }

    /** Jamais bloquer un vrai visiteur pour une panne côté Google. */
    public function test_passes_when_google_is_unreachable(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['www.google.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->assertTrue($this->validate('a-token')->passes());
    }

    public function test_contact_form_is_rejected_with_a_low_score_when_recaptcha_is_configured(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret', 'services.recaptcha.min_score' => 0.5]);
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'action' => 'contact', 'score' => 0.1])]);

        $this->post('/contact', [
            'name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'spam',
            'website' => '', 'recaptcha_token' => 'low-score-token',
        ])->assertSessionHasErrors('recaptcha_token');

        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot@example.test']);
    }

    public function test_contact_form_is_rejected_when_the_token_field_is_omitted_entirely(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);

        // Ni "recaptcha_token" ni "recaptcha_token => null" : le champ n'est
        // pas envoyé du tout, comme le ferait un bot basique.
        $this->post('/contact', [
            'name' => 'Bot', 'email' => 'bot-no-field@example.test', 'message' => 'spam', 'website' => '',
        ])->assertSessionHasErrors('recaptcha_token');

        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot-no-field@example.test']);
    }

    public function test_contact_form_succeeds_with_a_high_score(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret', 'services.recaptcha.min_score' => 0.5]);
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'action' => 'contact', 'score' => 0.9])]);

        $this->post('/contact', [
            'name' => 'Alice', 'email' => 'alice@example.test', 'message' => 'Bonjour',
            'website' => '', 'recaptcha_token' => 'high-score-token',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('contact_messages', ['email' => 'alice@example.test']);
    }
}
