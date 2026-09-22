<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sous-rubriques de l'annuaire (demande client, 19/09/2026 : "remets la
 * rubrique Restaurants dans les menus ... il y a plusieurs sous rubriques" —
 * ex. "Restaurant spectacle"). Root cause trouvée : la catégorie "Restaurants"
 * et ses sous-catégories existaient déjà en base (migration legacy) et
 * apparaissaient bien dans la sidebar (niveau 0), mais AUCUNE page n'exposait
 * les sous-rubriques d'une catégorie — impossible de naviguer vers
 * "Restaurant spectacle" sans en connaître l'URL exacte. Voir
 * ListingController::index() et TECHNICAL_DOCUMENTATION.md.
 */
class AnnuaireSubcategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function restaurantsWithSubcategory(): array
    {
        $restaurants = Category::create(['name' => 'Restaurants', 'slug' => 'restaurants']);
        $dinerSpectacle = Category::create(['name' => 'Restaurant spectacle', 'slug' => 'diner-spectacle', 'parent_id' => $restaurants->id]);
        Category::create(['name' => 'Guinguettes', 'slug' => 'guinguettes', 'parent_id' => $restaurants->id]);

        return [$restaurants, $dinerSpectacle];
    }

    public function test_subcategory_pills_are_shown_on_a_top_category_page(): void
    {
        [$restaurants] = $this->restaurantsWithSubcategory();

        $response = $this->get('/annuaire/'.$restaurants->slug)->assertOk();
        $response->assertSee('Restaurant spectacle');
        $response->assertSee('Guinguettes');
    }

    public function test_subcategory_pills_are_also_shown_when_already_browsing_a_subcategory(): void
    {
        [, $dinerSpectacle] = $this->restaurantsWithSubcategory();

        // Depuis "Restaurant spectacle", on doit pouvoir naviguer vers
        // l'autre sous-rubrique ("Guinguettes") sans revenir en arrière.
        $response = $this->get('/annuaire/'.$dinerSpectacle->slug)->assertOk();
        $response->assertSee('Restaurant spectacle');
        $response->assertSee('Guinguettes');
    }

    public function test_visiting_a_subcategory_only_shows_its_own_listings(): void
    {
        [$restaurants, $dinerSpectacle] = $this->restaurantsWithSubcategory();
        $guinguettes = Category::where('slug', 'guinguettes')->first();

        $cabaret = Listing::create(['title' => 'Cabaret O Toulouse', 'slug' => 'cabaret-o-toulouse', 'status' => 'published']);
        $cabaret->categories()->attach($dinerSpectacle->id);
        $guinguette = Listing::create(['title' => 'Guinguette Racines', 'slug' => 'guinguette-racines', 'status' => 'published']);
        $guinguette->categories()->attach($guinguettes->id);

        $response = $this->get('/annuaire/'.$dinerSpectacle->slug)->assertOk();
        $response->assertSee('Cabaret O Toulouse');
        $response->assertDontSee('Guinguette Racines');
    }

    public function test_sidebar_highlights_the_parent_category_when_browsing_a_subcategory(): void
    {
        [$restaurants, $dinerSpectacle] = $this->restaurantsWithSubcategory();

        $html = $this->get('/annuaire/'.$dinerSpectacle->slug)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#href="/annuaire/restaurants"[^>]*class="[^"]*bg-brand-50[^"]*"#s',
            $html,
            'Le lien sidebar "Restaurants" devrait rester actif en naviguant sur une de ses sous-rubriques.'
        );
    }

    public function test_breadcrumb_shows_the_full_ancestor_chain_for_a_subcategory(): void
    {
        [$restaurants, $dinerSpectacle] = $this->restaurantsWithSubcategory();

        $response = $this->get('/annuaire/'.$dinerSpectacle->slug)->assertOk();
        $response->assertSeeInOrder(['Annuaire', 'Restaurants', 'Restaurant spectacle']);
        // Le lien intermédiaire doit pointer vers Restaurants, pas juste être du texte.
        $response->assertSee('href="/annuaire/restaurants"', false);
    }

    public function test_no_subcategory_pills_on_the_full_annuaire_index(): void
    {
        $this->restaurantsWithSubcategory();
        Category::create(['name' => 'Sports', 'slug' => 'sports']);

        $this->get('/annuaire')->assertOk()->assertDontSee('Restaurant spectacle');
    }

    public function test_no_subcategory_pills_for_a_category_without_children(): void
    {
        $sports = Category::create(['name' => 'Sports', 'slug' => 'sports']);

        $this->get('/annuaire/'.$sports->slug)->assertOk()->assertDontSee('annuaire_subcategory');
    }

    /**
     * Demande client, 22/09/2026 : "cacher les descriptions longues entre le
     * titre de la catégorie et le sous-menu" — capture montrant du texte de
     * bourrage de mots-clés SEO ("a emporter toulouse, a emporter, toulouse
     * a emporter...") affiché en pleine page sur /annuaire/a-emporter. Le
     * champ reste utilisé pour le <meta name="description"> (SeoResolverService),
     * seul l'affichage visible en corps de page est retiré.
     */
    public function test_category_description_is_not_displayed_on_the_page_but_still_feeds_seo_meta(): void
    {
        $category = Category::create([
            'name' => 'A emporter', 'slug' => 'a-emporter-desc',
            'description' => 'a emporter toulouse, a emporter, toulouse a emporter, emporter toulouse',
        ]);

        $html = $this->get('/annuaire/'.$category->slug)->assertOk()->getContent();

        // Toujours dans le <meta name="description"> (SEO)...
        $this->assertStringContainsString(
            '<meta name="description" content="a emporter toulouse, a emporter, toulouse a emporter, emporter toulouse">',
            $html
        );
        // ...mais plus dans un <p> visible du corps de page (l'ancien rendu).
        $this->assertStringNotContainsString(
            '<p class="mt-4 max-w-3xl text-ink-600">a emporter toulouse',
            $html
        );
    }
}
