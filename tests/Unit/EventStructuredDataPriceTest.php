<?php

namespace Tests\Unit;

use App\Models\Event;
use Tests\TestCase;

/**
 * `Event::structuredDataPrice()` — bug réel trouvé et corrigé (02/10/2026,
 * Google Search Console : "Format de prix non valide dans la propriété
 * price") : `price` est un champ texte libre ("de 8 € à 15 €", "Tarif plein
 * : 22 € | ..."), jamais un simple nombre, rejeté par schema.org. Testé sur
 * un modèle NON persisté (l'accesseur ne lit que l'attribut en mémoire).
 */
class EventStructuredDataPriceTest extends TestCase
{
    public function test_extracts_the_lowest_amount_from_a_price_range(): void
    {
        $event = new Event(['price' => 'de 8 € à 15 €']);

        $this->assertSame('8', $event->structured_data_price);
    }

    public function test_extracts_the_first_amount_from_a_detailed_tariff_list(): void
    {
        $event = new Event(['price' => 'Tarif plein : 22 € | Tarif réduit : 19 € (sur justificatif)']);

        $this->assertSame('22', $event->structured_data_price);
    }

    public function test_handles_a_comma_decimal_separator(): void
    {
        $event = new Event(['price' => 'Tarif A (de 6,5€ à 11€)']);

        $this->assertSame('6.5', $event->structured_data_price);
    }

    public function test_falls_back_to_zero_for_gratuit(): void
    {
        $event = new Event(['price' => 'Gratuit']);

        $this->assertSame('0', $event->structured_data_price);
    }

    /**
     * ⚠️ Cas réel constaté en base : un bandeau de discipline ("Cirque
     * Musique Théâtre") stocké par erreur comme prix (bug corrigé côté
     * scraper, voir TheatreDeLaCiteDriver) — aucun montant à en extraire,
     * repli sur "0" plutôt qu'un prix inventé.
     */
    public function test_falls_back_to_zero_when_nothing_numeric_is_found(): void
    {
        $event = new Event(['price' => 'Cirque Musique Théâtre']);

        $this->assertSame('0', $event->structured_data_price);
    }

    public function test_falls_back_to_zero_when_price_is_null(): void
    {
        $event = new Event(['price' => null]);

        $this->assertSame('0', $event->structured_data_price);
    }
}
