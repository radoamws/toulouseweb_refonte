<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demande client (11/09/2026) : *"Où sont accessibles les formulaires que
 * j'avais demandé avant à part 'Déposer une annonce' et 'Proposer un
 * événement'. Les mettre accessible facilement."* — les 4 dépôts publics
 * (annonce, événement, fiche annuaire, actualité) n'avaient qu'un bouton
 * "+" sur leur propre page de section, invisibles partout ailleurs (y
 * compris la homepage). Un menu "Publier" global (components/site/header.blade.php)
 * les rend désormais accessibles depuis N'IMPORTE QUELLE page.
 */
class PublishMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_public_submission_forms_are_linked_from_any_page(): void
    {
        // /cinema n'a aucun rapport avec les 4 formulaires — sert de preuve
        // que le menu est bien GLOBAL, pas seulement sur leur propre section.
        $response = $this->get('/cinema')->assertOk();

        $response->assertSee('href="/annonces/deposer"', false);
        $response->assertSee('href="/agenda/proposer"', false);
        $response->assertSee('href="/annuaire/deposer"', false);
        $response->assertSee('href="/actualites/proposer"', false);
    }
}
