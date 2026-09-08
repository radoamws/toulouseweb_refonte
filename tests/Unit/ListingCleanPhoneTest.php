<?php

namespace Tests\Unit;

use App\Models\Listing;
use Tests\TestCase;

/**
 * `Listing::cleanPhone()` — bug réel trouvé et corrigé (08/09/2026, audit
 * SEO final, TECHNICAL_DOCUMENTATION.md §24) : 163/2978 fiches (5,5%) ont un
 * `phone` legacy mélangeant téléphone/email/HTML brut ("Tel: ...<br>Mail:
 * ..."), rendu tel quel dans le lien tel:, le texte visible ET le JSON-LD
 * LocalBusiness.telephone. Testé sur un modèle NON persisté (l'accesseur ne
 * lit que l'attribut `phone` en mémoire, pas besoin de base de données).
 */
class ListingCleanPhoneTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> [raw, expected] */
    public static function phoneProvider(): array
    {
        return [
            'plain phone, no noise' => ['06 07 50 52 98', '06 07 50 52 98'],
            'label + br + mail label' => ['Tel: 06 07 50 52 98<br>Mail: info@automatisme-o-c.', '06 07 50 52 98'],
            'accented label + dots + br + email label' => ['Tél: 05.61.62.60.53<br>email: atykabodyworks@hotma', '05.61.62.60.53'],
            'label with spaces + dash + inline email label' => ['Tel: 05 61 121 215 - Email : contacter@gianniferru', '05 61 121 215'],
            'label + br (self-closing with space) + email label' => ['Tel : 06 81 40 96 33<br />Email : entreprisedazet@', '06 81 40 96 33'],
            'br followed directly by an email, no label at all' => ['Tel : 05 61 62 89 41<br>contact@hotelraymond4toulo', '05 61 62 89 41'],
            'two phone numbers kept together' => ['Tel : 05 34 58 24 78 - 06 37 24 88 83 <br>contact@', '05 34 58 24 78 - 06 37 24 88 83'],
            'pure email, no phone at all' => ['Email : contact@julesetjulies.fr', null],
            'null input' => [null, null],
            'empty string' => ['', null],
        ];
    }

    /** @dataProvider phoneProvider */
    public function test_cleans_legacy_phone_values(?string $raw, ?string $expected): void
    {
        $listing = new Listing(['phone' => $raw]);

        $this->assertSame($expected, $listing->clean_phone);
    }

    public function test_does_not_mutate_the_raw_phone_column(): void
    {
        $listing = new Listing(['phone' => 'Tel: 06 07 50 52 98<br>Mail: info@automatisme-o-c.']);

        $listing->clean_phone; // accès à l'accesseur

        $this->assertSame('Tel: 06 07 50 52 98<br>Mail: info@automatisme-o-c.', $listing->phone);
    }
}
