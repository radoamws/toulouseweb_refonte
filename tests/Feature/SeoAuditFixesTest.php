<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\EventCategory;
use App\Models\NewsCategory;
use App\Models\Page;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correctifs de l'audit SEO/GEO/perf final (08/09/2026, demande client,
 * TECHNICAL_DOCUMENTATION.md §24) — un par bug/gap réel trouvé.
 */
class SeoAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bug réel le plus important trouvé par l'audit : `HomeController`
     * interroge bien `Page::where('key','home')` (code déjà correct), mais
     * sur une base fraîchement migrée, aucune Page n'a jamais cette clé
     * précise (voir correctif de `MigrateSeo::findOrCreatePage()`) — la
     * home servait donc systématiquement le titre/description générique du
     * layout au lieu du contenu migré. Ce test verrouille le CHEMIN DE RENDU
     * (`HomeController` -> `Page` -> `SeoMeta` -> layout) une fois la bonne
     * Page présente — indépendamment de `migrate:seo`, qui n'est pas testé
     * automatiquement (dépend d'une vraie connexion legacy).
     */
    public function test_home_renders_the_migrated_seo_content_when_the_home_page_exists(): void
    {
        $page = Page::create(['key' => 'home', 'title' => 'Accueil', 'slug' => 'accueil']);
        $page->seoMeta()->create([
            'title' => 'ToulouseWeb.com | Sorties, Agenda, Restaurants & Commerces',
            'description' => 'Guide des sorties, événements, cinémas, concerts, restos et commerces à Toulouse.',
        ]);

        $response = $this->get('/')->assertOk();
        $response->assertSee('ToulouseWeb.com | Sorties, Agenda, Restaurants', false);
        $response->assertSee('Guide des sorties, événements, cinémas', false);
    }

    public function test_agenda_index_falls_back_to_its_seo_menu_page_when_no_category(): void
    {
        $page = Page::create(['key' => 'seo-menu-agenda', 'title' => 'Agenda', 'slug' => 'agenda-menu']);
        $page->seoMeta()->create(['title' => 'Agenda Toulouse et Environs | Événements à venir']);

        $this->get('/agenda')->assertOk()->assertSee('Agenda Toulouse et Environs', false);
    }

    public function test_annonces_index_falls_back_to_its_seo_menu_page_when_no_category(): void
    {
        $page = Page::create(['key' => 'seo-menu-annonces', 'title' => 'Annonces', 'slug' => 'annonces-menu']);
        $page->seoMeta()->create(['title' => 'Annonces Toulouse – Petites Annonces']);

        $this->get('/annonces')->assertOk()->assertSee('Annonces Toulouse', false);
    }

    public function test_cinema_index_falls_back_to_its_seo_menu_page(): void
    {
        $page = Page::create(['key' => 'seo-menu-cinema', 'title' => 'Cinéma', 'slug' => 'cinema-menu']);
        $page->seoMeta()->create(['title' => 'Cinémas à Toulouse et Environs']);

        $this->get('/cinema')->assertOk()->assertSee('Cinémas à Toulouse et Environs', false);
    }

    /**
     * Contrairement à agenda/annonces/cinema/annuaire, aucun `t_seo_entity`
     * legacy "actualités" n'a jamais existé (vérifié) — repli final
     * hardcodé dans NewsController plutôt que de laisser /actualites sur le
     * générique du layout.
     */
    public function test_actualites_index_falls_back_to_hardcoded_seo_when_no_page_exists(): void
    {
        $this->get('/actualites')->assertOk()->assertSee('Actualités de Toulouse et sa région | ToulouseWeb', false);
    }

    public function test_annuaire_category_page_seo_still_uses_its_own_category_not_the_menu_fallback(): void
    {
        $category = \App\Models\Category::create(['name' => 'Restaurants', 'slug' => 'restaurants', 'level' => 0, 'is_active' => true]);
        $category->seoMeta()->create(['title' => 'Titre spécifique catégorie Restaurants']);

        $this->get('/annuaire/restaurants')->assertOk()->assertSee('Titre spécifique catégorie Restaurants', false);
    }

    /**
     * `Str::limit()` était appelé sans `preserveWords` et avec `$end` forcé
     * à '' — coupait en plein milieu d'un mot sans aucune indication de
     * troncature (constaté en direct sur la home : "...Commerces à" au lieu
     * de "...Commerces à Toulouse").
     */
    public function test_seo_title_truncation_preserves_words_and_adds_an_ellipsis(): void
    {
        $original = 'Un titre extrêmement long qui dépasse largement la limite de soixante-dix caractères recommandée pour un vrai titre SEO';
        $page = Page::create(['key' => 'seo-menu-cinema', 'title' => 'Cinéma', 'slug' => 'cinema-menu']);
        $page->seoMeta()->create(['title' => $original]);

        $title = $page->resolveSeo()['title'];

        $this->assertLessThanOrEqual(71, mb_strlen($title)); // 70 + le caractère … (compté comme 1 par mb_strlen)
        $this->assertStringEndsWith('…', $title);

        // Ne coupe pas en plein milieu d'un mot : ce qui précède "…" doit être
        // un préfixe du texte original s'arrêtant exactement à une frontière
        // de mot (espace ou fin de chaîne), jamais au milieu d'un mot.
        $truncated = rtrim(mb_substr($title, 0, -1));
        $this->assertTrue(str_starts_with($original, $truncated));
        $charAfter = mb_substr($original, mb_strlen($truncated), 1);
        $this->assertTrue($charAfter === '' || $charAfter === ' ');
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) : la
     * troncature s'appliquait INCONDITIONNELLEMENT, y compris à un titre déjà
     * personnalisé et déjà sous la limite recommandée — constaté en direct
     * sur la home : le titre migré (69 caractères, déjà correct) perdait
     * "Toulouse" une fois retaillé à l'ancienne limite de 60.
     */
    public function test_seo_title_already_under_the_limit_is_not_truncated(): void
    {
        $original = 'ToulouseWeb.com | Sorties, Agenda, Restaurants & Commerces à Toulouse'; // 69 caractères, déjà personnalisé
        $page = Page::create(['key' => 'home', 'title' => 'Accueil', 'slug' => 'accueil']);
        $page->seoMeta()->create(['title' => $original]);

        $this->assertSame($original, $page->resolveSeo()['title']);
    }

    public function test_homepage_has_exactly_one_h1(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    /**
     * `public/images/og-default.jpg` n'a jamais existé (404 confirmé en
     * direct) — chaque page sans image dédiée (dont la home) partageait un
     * aperçu de lien cassé sur les réseaux sociaux.
     */
    public function test_default_og_image_is_a_real_file_not_the_missing_placeholder(): void
    {
        $this->assertFileDoesNotExist(public_path('images/og-default.jpg'));
        $this->assertFileExists(public_path('branding/toulouseweb-icon.png'));

        $response = $this->get('/')->assertOk();
        $response->assertDontSee('og-default.jpg');
        $response->assertSee('branding/toulouseweb-icon.png', false);
        $response->assertSee('name="twitter:image"', false);
    }

    /** Manquant avant ce correctif — condition pour l'éligibilité à la "sitelinks search box" Google. */
    public function test_website_search_action_schema_is_present_on_every_page(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertSee('"@type":"WebSite"', false);
        $response->assertSee('"@type":"SearchAction"', false);
    }

    /**
     * `external_url` (fragment AlloCiné brut type "salle_gen_csalle=P0071.html",
     * confirmé non-exploitable sur 26/28 salles réelles) faisait échouer la
     * validation Rich Results Google (`url` doit être une URI absolue).
     */
    public function test_movie_theater_json_ld_url_is_the_canonical_page_not_the_raw_external_url(): void
    {
        $cinema = Cinema::create([
            'name' => 'Un cinéma', 'slug' => 'un-cinema', 'is_active' => true,
            'external_url' => 'salle_gen_csalle=P0071.html',
        ]);

        $response = $this->get('/cinema/salles/un-cinema')->assertOk();
        // json_encode() échappe les "/" en "\/" par défaut — comparer à la
        // même représentation que celle réellement produite par le code.
        $response->assertSee('"url":"'.str_replace('/', '\/', route('cinema.salle', $cinema->slug)), false);
        $response->assertDontSee('salle_gen_csalle');
    }

    /** Défense en profondeur : robots.txt bloque déjà /admin, ce header empêche aussi une indexation si un lien externe y pointait. */
    public function test_admin_pages_send_a_noindex_header(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_public_pages_do_not_send_a_noindex_header(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertFalse($response->headers->has('X-Robots-Tag'));
    }

    /**
     * `excerpt` est un simple TextInput admin (pas un éditeur riche), mais
     * rien n'empêche un éditeur d'y coller du HTML (constaté en direct :
     * du "<p>...</p>" brut dans le JSON-LD NewsArticle.description).
     */
    public function test_news_article_json_ld_description_strips_html_and_includes_publisher(): void
    {
        SiteSetting::current(); // s'assure que le singleton par défaut existe

        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        \App\Models\News::create([
            'category_id' => $category->id, 'title' => 'Une actu', 'slug' => 'une-actu-jsonld',
            // Pas d'accents ici : json_encode() les échappe en \uXXXX par
            // défaut (comportement du code, pas un bug) — on garde la chaîne
            // de test en ASCII pour comparer directement au JSON produit.
            'body' => 'x', 'excerpt' => '<p>Un extrait avec du <strong>HTML</strong> colle par erreur</p>',
            'status' => 'published', 'published_at' => now(),
        ]);

        $response = $this->get('/actualites/une-actu-jsonld')->assertOk();
        $response->assertSee('"description":"Un extrait avec du HTML colle par erreur"', false);
        $response->assertDontSee('&lt;p&gt;', false);
        $response->assertSee('"publisher":{"@type":"Organization"', false);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) : `og:image`/
     * `twitter:image`/JSON-LD `image` contenaient le chemin BRUT stocké en
     * base (jamais résolu en URL absolue, jamais encodé) — un `<img src>`
     * classique fonctionne quand même (un navigateur ré-encode
     * silencieusement les espaces d'un attribut src), mais un crawler externe
     * qui lit la valeur brute d'une balise meta échoue à charger l'image.
     * Portée mesurée : 231/233 actualités publiées avaient un chemin ainsi
     * cassé.
     */
    public function test_news_og_image_is_resolved_and_url_encoded(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale-image']);
        \App\Models\News::create([
            'category_id' => $category->id, 'title' => 'Une actu avec image', 'slug' => 'une-actu-avec-image',
            'body' => 'x', 'image' => 'news/Laloum & Consuelo.jpg',
            'status' => 'published', 'published_at' => now(),
        ]);

        $expectedUrl = \Illuminate\Support\Facades\Storage::disk('public')->url('news/Laloum%20%26%20Consuelo.jpg');

        $response = $this->get('/actualites/une-actu-avec-image')->assertOk();
        $response->assertSee('property="og:image" content="'.$expectedUrl.'"', false);
        $response->assertDontSee('content="news/Laloum & Consuelo.jpg"', false);
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit SEO/GEO) : une page
     * paginée (?page=2) se voyait TOUJOURS attribuer le canonical de la
     * page 1 (contenu pourtant réellement différent) — voir
     * components/layouts/app.blade.php. `?q=` doit, lui, continuer à
     * canonicaliser vers la page sans filtre (comportement déjà correct,
     * évite d'indexer une infinité de variantes de recherche).
     */
    public function test_paginated_listing_pages_self_canonicalize(): void
    {
        $category = \App\Models\Category::create(['name' => 'Restaurants', 'slug' => 'restaurants-pagination', 'level' => 0, 'is_active' => true]);
        foreach (range(1, 30) as $i) {
            $listing = \App\Models\Listing::create(['title' => "Resto {$i}", 'slug' => "resto-{$i}", 'tier' => 'free', 'status' => 'published']);
            $listing->categories()->attach($category->id);
        }

        $this->get('/annuaire?page=2')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/annuaire?page=2').'">', false);

        // Toujours correct : la recherche continue de canonicaliser vers la page sans filtre.
        $this->get('/annuaire?q=resto')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/annuaire').'">', false);
    }
}
