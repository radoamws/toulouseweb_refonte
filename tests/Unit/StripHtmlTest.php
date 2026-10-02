<?php

namespace Tests\Unit;

use App\Services\Migration\LegacyCleaner;
use PHPUnit\Framework\TestCase;

/**
 * `LegacyCleaner::stripHtml()` — bug réel trouvé et corrigé (02/10/2026,
 * signalé par le client, exemple réel : "<b>Un ingénieur à la maison</b><br>6
 * Avenue de la Gloire - Toulouse" affiché LITTÉRALEMENT (balises visibles en
 * texte) sur la fiche annuaire — voir Listing::cleanAddress()/
 * Area::cleanAddress()/StripHtmlFromNewsTitles.
 */
class StripHtmlTest extends TestCase
{
    public function test_strips_bold_tag_and_converts_br_to_a_space(): void
    {
        $this->assertSame(
            'Un ingénieur à la maison 6 Avenue de la Gloire - Toulouse',
            LegacyCleaner::stripHtml('<b>Un ingénieur à la maison</b><br>6 Avenue de la Gloire - Toulouse')
        );
    }

    public function test_handles_self_closing_br_with_a_space(): void
    {
        $this->assertSame(
            'Ambiance Marine, le Centre de soins esthétiques Spa - Relaxation - Massages',
            LegacyCleaner::stripHtml('Ambiance Marine, le Centre de soins esthétiques<br />Spa - Relaxation - Massages')
        );
    }

    public function test_handles_multi_line_address_with_several_br(): void
    {
        $this->assertSame(
            "Hôtel d'Assézat Place d'Assézat 31000 TOULOUSE",
            LegacyCleaner::stripHtml("Hôtel d'Assézat<br>Place d'Assézat <br>31000 TOULOUSE")
        );
    }

    /**
     * ⚠️ Piège réel (constaté en base) : un "<" littéral utilisé comme
     * séparateur de date ("13<17 decembre") n'est PAS une balise HTML — ne
     * doit jamais être touché (ni par la conversion `<br>`, ni par
     * `strip_tags()`, qui exige un nom de balise valide immédiatement après
     * le `<`).
     */
    public function test_leaves_a_literal_less_than_sign_used_as_a_separator_untouched(): void
    {
        $text = 'Marin / Belarbi - Théâtre Garonne - 13<17 decembre';

        $this->assertSame($text, LegacyCleaner::stripHtml($text));
    }

    public function test_leaves_a_mathematical_less_than_sign_untouched(): void
    {
        $text = '10 Euros (red. 5, gratuit <12 ans)';

        $this->assertSame($text, LegacyCleaner::stripHtml($text));
    }

    public function test_decodes_html_entities(): void
    {
        $this->assertSame('ADE Cuisines & Agencements', LegacyCleaner::stripHtml('<b>ADE Cuisines &amp; Agencements</b>'));
    }

    public function test_null_and_empty_input_return_null(): void
    {
        $this->assertNull(LegacyCleaner::stripHtml(null));
        $this->assertNull(LegacyCleaner::stripHtml(''));
        $this->assertNull(LegacyCleaner::stripHtml('<b></b>'));
    }
}
