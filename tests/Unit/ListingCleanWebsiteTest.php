<?php

namespace Tests\Unit;

use App\Models\Listing;
use Tests\TestCase;

/**
 * `Listing::cleanWebsite()`/`cleanReservationUrl()` — bug réel trouvé et
 * corrigé (02/10/2026, signalé par le client : lien "Visiter le site" de
 * https://toulouseweb.com/annuaire/fiche/www.coursdedansetoulouse.fr
 * pointant vers "toulouseweb.com/annuaire/fiche/www.coursdedansetoulouse.fr"
 * au lieu du vrai site externe) — `website` legacy est parfois stocké sans
 * schéma, interprété par le navigateur comme un chemin relatif à la page
 * courante. Testé sur un modèle NON persisté (l'accesseur ne lit que
 * l'attribut en mémoire, pas besoin de base de données).
 */
class ListingCleanWebsiteTest extends TestCase
{
    public function test_adds_https_to_a_website_without_a_scheme(): void
    {
        $listing = new Listing(['website' => 'www.coursdedansetoulouse.fr']);

        $this->assertSame('https://www.coursdedansetoulouse.fr', $listing->clean_website);
    }

    public function test_leaves_a_website_with_an_existing_scheme_untouched(): void
    {
        $listing = new Listing(['website' => 'http://example.fr']);

        $this->assertSame('http://example.fr', $listing->clean_website);
    }

    public function test_does_not_mutate_the_raw_website_column(): void
    {
        $listing = new Listing(['website' => 'www.coursdedansetoulouse.fr']);

        $listing->clean_website; // accès à l'accesseur

        $this->assertSame('www.coursdedansetoulouse.fr', $listing->website);
    }

    public function test_null_website_returns_null(): void
    {
        $listing = new Listing(['website' => null]);

        $this->assertNull($listing->clean_website);
    }

    public function test_adds_https_to_a_reservation_url_without_a_scheme(): void
    {
        $listing = new Listing(['reservation_url' => 'www.exemple-resto.fr/reservation']);

        $this->assertSame('https://www.exemple-resto.fr/reservation', $listing->clean_reservation_url);
    }
}
