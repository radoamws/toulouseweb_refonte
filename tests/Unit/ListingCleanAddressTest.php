<?php

namespace Tests\Unit;

use App\Models\Listing;
use Tests\TestCase;

/**
 * `Listing::cleanAddress()` — bug réel trouvé et corrigé (02/10/2026,
 * signalé par le client, exemple réel : "<b>Un ingénieur à la maison</b><br>6
 * Avenue de la Gloire - Toulouse", confirmé en base sur 659/2978 fiches
 * annuaire). Testé sur un modèle NON persisté (l'accesseur ne lit que
 * l'attribut en mémoire, pas besoin de base de données).
 */
class ListingCleanAddressTest extends TestCase
{
    public function test_strips_html_from_a_legacy_address(): void
    {
        $listing = new Listing(['address' => '<b>Un ingénieur à la maison</b><br>6 Avenue de la Gloire - Toulouse']);

        $this->assertSame('Un ingénieur à la maison 6 Avenue de la Gloire - Toulouse', $listing->clean_address);
    }

    public function test_leaves_a_clean_address_untouched(): void
    {
        $listing = new Listing(['address' => '6 Avenue de la Gloire - Toulouse']);

        $this->assertSame('6 Avenue de la Gloire - Toulouse', $listing->clean_address);
    }

    public function test_does_not_mutate_the_raw_address_column(): void
    {
        $listing = new Listing(['address' => '<b>Un ingénieur à la maison</b><br>6 Avenue de la Gloire - Toulouse']);

        $listing->clean_address; // accès à l'accesseur

        $this->assertSame('<b>Un ingénieur à la maison</b><br>6 Avenue de la Gloire - Toulouse', $listing->address);
    }

    public function test_null_address_returns_null(): void
    {
        $listing = new Listing(['address' => null]);

        $this->assertNull($listing->clean_address);
    }
}
