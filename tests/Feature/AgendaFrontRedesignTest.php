<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refonte de /agenda (demande client, 18/09/2026) :
 * 1. Date de début ET de fin sur chaque fiche de la liste.
 * 2. Calendrier fusionné avec la liste (plus de bascule séparée) — cliquer
 *    un jour filtre la liste, en tenant compte de la catégorie choisie.
 * 3. Couleur distincte par catégorie (pastille de menu + bordure des fiches).
 * 4. Filtre par lieu (salle).
 *
 * Voir TECHNICAL_DOCUMENTATION.md §46. Les tests calendrier/pagination déjà
 * existants (bascule liste/calendrier, navigation mois) sont dans
 * PublicContentPagesTest.php, mis à jour dans le même commit.
 */
class AgendaFrontRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_card_shows_start_and_end_date(): void
    {
        Event::create([
            'title' => 'Festival de rue', 'slug' => 'festival-de-rue', 'status' => 'published',
            'start_date' => '2026-10-03', 'end_date' => '2026-10-05',
        ]);
        // Un événement d'un seul jour n'affiche pas de flèche "→" inutile.
        Event::create([
            'title' => 'Concert unique', 'slug' => 'concert-unique', 'status' => 'published',
            'start_date' => '2026-10-10', 'end_date' => '2026-10-10',
        ]);

        $response = $this->get('/agenda')->assertOk();

        $response->assertSee('03 oct. 2026 → 05 oct. 2026', false);
        $response->assertDontSee('10 oct. 2026 → 10 oct. 2026', false);
        $response->assertSee('10 oct. 2026');
    }

    /** Le calendrier est toujours visible, plus besoin d'un onglet séparé pour le faire apparaître. */
    public function test_calendar_and_list_are_shown_together_without_a_view_toggle(): void
    {
        $response = $this->get('/agenda')->assertOk();

        $response->assertDontSee('Calendrier');
        // Le sélecteur de mois (toujours présent) prouve que le calendrier
        // est bien rendu par défaut, sans paramètre `view=calendar`.
        $response->assertSee(now()->translatedFormat('F Y'));
    }

    public function test_clicking_a_calendar_day_narrows_the_list_respecting_the_selected_category(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre', 'slug' => 'theatre-redesign', 'color' => '#3a9973']);
        $concert = EventCategory::create(['name' => 'Concerts', 'slug' => 'concerts-redesign', 'color' => '#e31f1d']);

        $targetDay = now()->addDays(5)->startOfDay();
        $otherDay = now()->addDays(10)->startOfDay();

        $theatreOnTargetDay = Event::create(['title' => 'Pièce du jour J', 'slug' => 'piece-du-jour-j', 'status' => 'published', 'start_date' => $targetDay]);
        $theatreOnTargetDay->categories()->attach($theatre);

        $concertOnTargetDay = Event::create(['title' => 'Concert du jour J', 'slug' => 'concert-du-jour-j', 'status' => 'published', 'start_date' => $targetDay]);
        $concertOnTargetDay->categories()->attach($concert);

        $theatreOnOtherDay = Event::create(['title' => 'Pièce autre jour', 'slug' => 'piece-autre-jour', 'status' => 'published', 'start_date' => $otherDay]);
        $theatreOnOtherDay->categories()->attach($theatre);

        // Filtré uniquement par catégorie "Théâtre" (sans date) : les 2 pièces, pas le concert.
        $response = $this->get('/agenda/theatre-redesign')->assertOk();
        $response->assertSee('Pièce du jour J')->assertSee('Pièce autre jour')->assertDontSee('Concert du jour J');

        // Catégorie + jour précis (simulateur du clic sur le calendrier) :
        // uniquement la pièce DE CE JOUR, ni l'autre pièce ni le concert.
        $response = $this->get('/agenda/theatre-redesign?date='.$targetDay->format('Y-m-d'))->assertOk();
        $response->assertSee('Pièce du jour J')
            ->assertDontSee('Pièce autre jour')
            ->assertDontSee('Concert du jour J');
    }

    public function test_category_pills_and_event_cards_use_the_category_color(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre Couleur', 'slug' => 'theatre-couleur', 'color' => '#3a9973']);
        $event = Event::create(['title' => 'Pièce colorée', 'slug' => 'piece-coloree', 'status' => 'published', 'start_date' => now()->addDay()]);
        $event->categories()->attach($theatre);

        $response = $this->get('/agenda')->assertOk();

        // La pastille de la catégorie dans le menu.
        $response->assertSee('background-color: #3a9973', false);
        // La bordure gauche de la fiche événement.
        $response->assertSee('border-left-color: #3a9973', false);
    }

    /**
     * Demande client, 22/09/2026 : "le menu des agenda doivent avoir des
     * couleurs de fond comme les pastilles dans les encadrés" — le fond
     * teinté (color-mix) n'était jusqu'ici QUE sur le badge des fiches
     * événement, pas sur les pastilles du menu (qui n'avaient qu'un petit
     * point de couleur + une bordure). Teinte relevée à 28% le 23/09/2026
     * ("ce n'est pas trop distinct").
     */
    public function test_category_menu_pill_has_a_tinted_background_like_the_card_badge(): void
    {
        EventCategory::create(['name' => 'Exposition', 'slug' => 'exposition-fond', 'color' => '#1d6fa5']);

        $response = $this->get('/agenda')->assertOk();

        $response->assertSee('background-color: color-mix(in srgb, #1d6fa5 28%, white); color: #1d6fa5;', false);
    }

    /**
     * Demande client, 23/09/2026 : "le fond blanc de chaque encadré ait la
     * même couleur que la catégorie correspondante pour plus de mise en
     * valeur" — jusqu'ici le fond de la carte restait `bg-white` quelle que
     * soit sa catégorie (seuls la bordure gauche et le badge étaient
     * colorés). Teinte relevée à 22% le même jour ("ce n'est pas trop
     * distinct").
     */
    public function test_event_card_background_is_tinted_with_the_category_color(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre Fond', 'slug' => 'theatre-fond', 'color' => '#3a9973']);
        $event = Event::create(['title' => 'Pièce au fond teinté', 'slug' => 'piece-fond-teinte', 'status' => 'published', 'start_date' => now()->addDay()]);
        $event->categories()->attach($theatre);

        $response = $this->get('/agenda')->assertOk();

        $response->assertSee('background-color: color-mix(in srgb, #3a9973 22%, white)', false);
    }

    /** Un événement SANS catégorie garde un fond blanc classique (pas de couleur à en tirer). */
    public function test_event_card_without_a_category_keeps_a_plain_white_background(): void
    {
        Event::create(['title' => 'Sans catégorie', 'slug' => 'sans-categorie-fond', 'status' => 'published', 'start_date' => now()->addDay()]);

        $html = $this->get('/agenda')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="\/agenda\/sans-categorie-fond"[^>]*class="[^"]*bg-white/s', $html);
    }

    public function test_area_filter_narrows_the_list(): void
    {
        $areaA = Area::create(['name' => 'Zénith de Toulouse', 'slug' => 'zenith-toulouse-redesign']);
        $areaB = Area::create(['name' => 'Le Bikini', 'slug' => 'le-bikini-redesign']);

        $eventA = Event::create(['title' => 'Concert au Zénith', 'slug' => 'concert-zenith', 'status' => 'published', 'area_id' => $areaA->id, 'start_date' => now()->addDay()]);
        $eventB = Event::create(['title' => 'Concert au Bikini', 'slug' => 'concert-bikini', 'status' => 'published', 'area_id' => $areaB->id, 'start_date' => now()->addDay()]);

        $response = $this->get('/agenda')->assertOk();
        $response->assertSee('Concert au Zénith')->assertSee('Concert au Bikini');
        // Seuls les lieux ayant un événement publié apparaissent dans le filtre.
        $response->assertSee('Zénith de Toulouse')->assertSee('Le Bikini');

        $response = $this->get('/agenda?area='.$areaA->id)->assertOk();
        $response->assertSee('Concert au Zénith')->assertDontSee('Concert au Bikini');
    }

    /** Un lieu sans aucun événement publié n'encombre pas le filtre (~3845 lieux au total en production, voir docblock du contrôleur). */
    public function test_area_filter_excludes_venues_without_published_events(): void
    {
        Area::create(['name' => 'Lieu Sans Événement', 'slug' => 'lieu-sans-evenement']);

        $response = $this->get('/agenda')->assertOk();

        $response->assertDontSee('Lieu Sans Événement');
    }

    /**
     * Demande client, 22/09/2026 : "faire une recherche par on-change +
     * appui sur entrée et non avec un bouton Filtrer. Faire en on-change
     * aussi le filtre par Lieu" — le formulaire se soumet désormais tout
     * seul, plus de bouton "Filtrer" visible.
     */
    public function test_search_and_area_filter_submit_automatically_without_a_filter_button(): void
    {
        $area = Area::create(['name' => 'Zénith Auto Submit', 'slug' => 'zenith-auto-submit']);
        Event::create(['title' => 'Concert test', 'slug' => 'concert-test-auto', 'status' => 'published', 'area_id' => $area->id, 'start_date' => now()->addDay()]);

        $response = $this->get('/agenda')->assertOk();

        $response->assertDontSeeText('Filtrer');
        $response->assertSee('id="q"', false);
        $response->assertSee('onchange="this.form.submit()"', false);
        $response->assertSee('onkeydown="if (event.key === \'Enter\')', false);
        $response->assertSee('<select name="area" id="area" onchange="this.form.submit()"', false);
    }

    /**
     * Demande client, 23/09/2026 : "permettre aussi dans la zone de
     * recherche libre pour rechercher les catégories : 'escale' doit
     * inclure [...] les encadrés de l'escale" — jusqu'ici la recherche ne
     * portait que sur le titre de l'événement.
     */
    public function test_search_also_matches_the_venue_name(): void
    {
        $escale = Area::create(['name' => "L'Escale", 'slug' => 'lescale-search']);
        $other = Area::create(['name' => 'Le Zénith', 'slug' => 'le-zenith-search']);

        $atEscale = Event::create(['title' => 'Spectacle du soir', 'slug' => 'spectacle-du-soir-search', 'status' => 'published', 'area_id' => $escale->id, 'start_date' => now()->addDay()]);
        $elsewhere = Event::create(['title' => 'Concert ailleurs', 'slug' => 'concert-ailleurs-search', 'status' => 'published', 'area_id' => $other->id, 'start_date' => now()->addDay()]);

        $response = $this->get('/agenda?q=escale')->assertOk();

        $response->assertSee('Spectacle du soir')->assertDontSee('Concert ailleurs');
    }

    public function test_search_also_matches_the_category_name(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre Recherche', 'slug' => 'theatre-recherche', 'color' => '#3a9973']);
        $sport = EventCategory::create(['name' => 'Sports Recherche', 'slug' => 'sports-recherche', 'color' => '#a63f23']);

        $play = Event::create(['title' => 'Pièce sans le mot-clé', 'slug' => 'piece-sans-mot-cle', 'status' => 'published', 'start_date' => now()->addDay()]);
        $play->categories()->attach($theatre);
        $match = Event::create(['title' => 'Match sans le mot-clé', 'slug' => 'match-sans-mot-cle', 'status' => 'published', 'start_date' => now()->addDay()]);
        $match->categories()->attach($sport);

        $response = $this->get('/agenda?q=Théâtre+Recherche')->assertOk();

        $response->assertSee('Pièce sans le mot-clé')->assertDontSee('Match sans le mot-clé');
    }

    /** La recherche élargie doit rester compatible avec le filtre catégorie déjà actif (combinaison AND, pas OR global). */
    public function test_search_combines_with_the_active_category_filter(): void
    {
        $theatre = EventCategory::create(['name' => 'Théâtre Combiné', 'slug' => 'theatre-combine', 'color' => '#3a9973']);
        $sport = EventCategory::create(['name' => 'Sports Combiné', 'slug' => 'sports-combine', 'color' => '#a63f23']);
        $escale = Area::create(['name' => "L'Escale", 'slug' => 'lescale-combine']);

        $matches = Event::create(['title' => 'Théâtre à L\'Escale', 'slug' => 'theatre-escale-combine', 'status' => 'published', 'area_id' => $escale->id, 'start_date' => now()->addDay()]);
        $matches->categories()->attach($theatre);
        $wrongCategory = Event::create(['title' => 'Sport à L\'Escale', 'slug' => 'sport-escale-combine', 'status' => 'published', 'area_id' => $escale->id, 'start_date' => now()->addDay()]);
        $wrongCategory->categories()->attach($sport);

        $response = $this->get('/agenda/'.$theatre->slug.'?q=escale')->assertOk();

        $response->assertSee('Théâtre à L\'Escale')->assertDontSee('Sport à L\'Escale');
    }
}
