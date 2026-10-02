<?php

namespace Tests\Unit;

use App\Services\Migration\LegacyCleaner;
use PHPUnit\Framework\TestCase;

/**
 * `LegacyCleaner::postalAndCity()` — extraction best-effort ville/code
 * postal depuis `t_article.adresse` (texte libre legacy), voir docblock de
 * ListingController et TECHNICAL_DOCUMENTATION.md §13 ("recherche
 * géographique", brief §5). Cas réels observés en base `toulouseweb_old`
 * (25/08/2026), positifs et négatifs.
 */
class LegacyCleanerTest extends TestCase
{
    public function test_parses_standard_french_address(): void
    {
        [$postal, $city] = LegacyCleaner::postalAndCity('6 rue louis Bonin 31200 Toulouse');

        $this->assertSame('31200', $postal);
        $this->assertSame('Toulouse', $city);
    }

    public function test_strips_html_before_parsing(): void
    {
        [$postal, $city] = LegacyCleaner::postalAndCity('<b>Yam Service</b><br>6 rue louis Bonin 31200 Toulouse');

        $this->assertSame('31200', $postal);
        $this->assertSame('Toulouse', $city);
    }

    public function test_handles_comma_separated_address(): void
    {
        [$postal, $city] = LegacyCleaner::postalAndCity('60 Grande Rue Saint Michel, 31400 Toulouse');

        $this->assertSame('31400', $postal);
        $this->assertSame('Toulouse', $city);
    }

    public function test_normalizes_inconsistent_casing(): void
    {
        [, $city] = LegacyCleaner::postalAndCity('27 Rue Louis Plana 31500 COLOMIERS');

        $this->assertSame('Colomiers', $city);
    }

    public function test_multi_word_city_name(): void
    {
        [$postal, $city] = LegacyCleaner::postalAndCity('34 Rue Saint Laurent 31270 Villeneuve Tolosane');

        $this->assertSame('31270', $postal);
        $this->assertSame('Villeneuve Tolosane', $city);
    }

    /** @dataProvider unparseableAddressProvider */
    public function test_returns_null_for_unparseable_addresses(string $address): void
    {
        $this->assertSame([null, null], LegacyCleaner::postalAndCity($address));
    }

    public static function unparseableAddressProvider(): array
    {
        return [
            'no postal code' => ['19 rue henri regnault'],
            'url only' => ['http://www.leselectionspresidentielles.com/'],
            'marketing text' => ['Consultez notre vidéo de présentation'],
            'foreign format without 5-digit code' => ['Rue Haldimand 15'],
            'empty' => [''],
        ];
    }

    public function test_null_input_returns_null_pair(): void
    {
        $this->assertSame([null, null], LegacyCleaner::postalAndCity(null));
    }

    /**
     * `LegacyCleaner::normalizeUrl()` — bug réel trouvé et corrigé (02/10/2026,
     * signalé par le client : lien "Visiter le site" d'une fiche annuaire
     * pointant vers "toulouseweb.com/annuaire/fiche/www.exemple.fr" au lieu
     * du vrai site externe) — voir docblock de la méthode et de
     * Listing::cleanWebsite().
     */
    public function test_normalize_url_adds_https_when_no_scheme_is_present(): void
    {
        $this->assertSame('https://www.coursdedansetoulouse.fr', LegacyCleaner::normalizeUrl('www.coursdedansetoulouse.fr'));
    }

    public function test_normalize_url_leaves_an_existing_scheme_untouched(): void
    {
        $this->assertSame('http://example.fr', LegacyCleaner::normalizeUrl('http://example.fr'));
        $this->assertSame('https://example.fr', LegacyCleaner::normalizeUrl('https://example.fr'));
    }

    public function test_normalize_url_returns_null_for_null_or_empty_input(): void
    {
        $this->assertNull(LegacyCleaner::normalizeUrl(null));
        $this->assertNull(LegacyCleaner::normalizeUrl(''));
    }
}
