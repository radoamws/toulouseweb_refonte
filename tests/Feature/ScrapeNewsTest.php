<?php

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Services\Scraping\News\LeJournalToulousainDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Scraper d'actualités locales — Le Journal Toulousain (demande client,
 * 20/11/2026 : "les news ne sont pas à jour"), voir le docblock de
 * LeJournalToulousainDriver pour le choix de la source (flux RSS officiel,
 * `actu.fr` écarté — bloqué par un anti-bot) et TECHNICAL_DOCUMENTATION.md §82.
 *
 * Le fragment RSS ci-dessous reprend fidèlement la structure réelle d'un
 * flux WordPress/Yoast (CDATA sur `<description>`/`<content:encoded>`,
 * namespace `content`), vérifiée en direct contre le vrai flux le
 * 20/11/2026 — pas une supposition.
 */
class ScrapeNewsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(array $configOverrides = []): ScraperSource
    {
        return ScraperSource::create([
            'name' => 'Le Journal Toulousain',
            'type' => 'news',
            'driver_class' => LeJournalToulousainDriver::class,
            'config' => array_merge([
                'feed_url' => 'https://www.lejournaltoulousain.fr/occitanie/haute-garonne/toulouse/feed/',
                'category_slug' => 'vie-locale',
            ], $configOverrides),
            'is_active' => true,
        ]);
    }

    protected function item(string $link, string $title, string $pubDate = 'Fri, 20 Nov 2026 08:05:00 +0000', string $description = 'Un résumé de l\'article.', string $contentHtml = '<p>Corps complet de l\'article, jamais republié.</p><img src="https://www.lejournaltoulousain.fr/wp-content/uploads/2026/11/photo.jpg" />'): string
    {
        return <<<XML
            <item>
                <title><![CDATA[{$title}]]></title>
                <link>{$link}</link>
                <pubDate>{$pubDate}</pubDate>
                <dc:creator><![CDATA[Rédaction]]></dc:creator>
                <category><![CDATA[Toulouse]]></category>
                <description><![CDATA[{$description}]]></description>
                <content:encoded><![CDATA[{$contentHtml}]]></content:encoded>
            </item>
        XML;
    }

    protected function feed(array $items): string
    {
        $itemsXml = implode('', $items);

        // ⚠️ Bug réel trouvé en écrivant ce test (20/11/2026) : l'indentation
        // "flexible" des heredocs PHP (7.3+) ne retire QUE l'indentation du
        // marqueur de fermeture — ici insuffisant, laissant des espaces
        // résiduels avant la déclaration XML elle-même. Invalide en XML
        // strict (cette déclaration n'est autorisée qu'en tout premier
        // caractère du document — confirmé en isolant l'erreur réelle via
        // `libxml_get_errors()`, masquée jusque-là par le `@` de
        // `LeJournalToulousainDriver::fetchFeed()`) : `simplexml_load_string`
        // échouait silencieusement sur CE fixture précis. `ltrim()` au lieu
        // de compter sur l'indentation du heredoc pour être exacte.
        //
        // ⚠️ Piège PHP rencontré EN CORRIGEANT ce commentaire : un simple
        // commentaire `//` se termine au premier saut de ligne OU à la
        // première séquence de fermeture de balise PHP rencontrée — jamais
        // écrire cette séquence (chevron fermant suivi d'un signe supérieur)
        // en toutes lettres dans un commentaire sur une seule ligne, sous
        // peine de faire sortir le lexer du mode PHP en plein milieu du
        // fichier (tout le reste était alors interprété comme du texte
        // brut, d'où le "Unclosed '{'" signalé bien plus loin).
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
                <channel>
                    <title>Le Journal Toulousain</title>
                    <link>https://www.lejournaltoulousain.fr</link>
                    {$itemsXml}
                </channel>
            </rss>
        XML;

        return ltrim($xml);
    }

    public function test_new_article_is_created_as_an_excerpt_with_a_link_to_the_source(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $source = $this->makeSource();

        Http::fake([
            '*lejournaltoulousain.fr*' => Http::response($this->feed([
                $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Un article de test'),
            ])),
        ]);

        $exitCode = $this->artisan('scrape:news')->run();

        $this->assertSame(0, $exitCode);

        $news = News::where('external_ref', 'https://www.lejournaltoulousain.fr/toulouse/un-article-404526/')->first();
        $this->assertNotNull($news);
        $this->assertSame('Un article de test', $news->title);
        $this->assertSame('published', $news->status);
        $this->assertSame('scraped', $news->source);
        $this->assertSame('vie-locale', $news->category->slug);
        $this->assertSame('2026-11-20 08:05:00', $news->published_at->format('Y-m-d H:i:s'));

        // Extrait repris, JAMAIS le corps complet (décision client,
        // 20/11/2026 — voir docblock de la classe).
        $this->assertStringContainsString("Un résumé de l'article.", $news->excerpt);
        $this->assertStringNotContainsString('Corps complet de l\'article', $news->body);
        $this->assertSame('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', $news->website);

        // Image reprise depuis le corps complet (seule donnée qui en est extraite).
        $this->assertSame('https://www.lejournaltoulousain.fr/wp-content/uploads/2026/11/photo.jpg', $news->image);

        $run = ScraperRun::where('source_id', $source->id)->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->items_created);
    }

    public function test_existing_article_is_updated_not_duplicated(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $this->makeSource();

        // ⚠️ `Http::fake()` appelé une seconde fois avec le même motif d'URL
        // ne remplace PAS le premier stub : Laravel teste les stubs dans leur
        // ordre d'enregistrement et s'arrête au premier qui correspond — le
        // second appel n'a donc aucun effet tant que le motif est identique.
        // Une seule déclaration avec une séquence de réponses est nécessaire
        // pour simuler deux passages successifs du scraper.
        Http::fake([
            '*lejournaltoulousain.fr*' => Http::sequence()
                ->push($this->feed([
                    $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Titre original'),
                ]))
                ->push($this->feed([
                    $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Titre mis à jour'),
                ])),
        ]);

        $this->artisan('scrape:news')->run();
        $this->artisan('scrape:news')->run();

        $this->assertSame(1, News::count());
        $this->assertSame('Titre mis à jour', News::first()->title);
    }

    public function test_article_without_a_parseable_date_is_skipped(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $this->makeSource();

        Http::fake([
            '*lejournaltoulousain.fr*' => Http::response($this->feed([
                $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Sans date', pubDate: ''),
            ])),
        ]);

        $this->artisan('scrape:news')->run();

        $this->assertDatabaseCount('news', 0);
    }

    public function test_missing_category_throws_instead_of_silently_skipping(): void
    {
        // Aucune NewsCategory 'vie-locale' créée.
        $this->makeSource();

        Http::fake([
            '*lejournaltoulousain.fr*' => Http::response($this->feed([
                $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Un article'),
            ])),
        ]);

        $exitCode = $this->artisan('scrape:news')->run();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', ScraperRun::first()->status);
    }

    public function test_upstream_failure_marks_run_as_failed_without_crashing(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $this->makeSource();

        Http::fake(['*lejournaltoulousain.fr*' => Http::response('', 500)]);

        $exitCode = $this->artisan('scrape:news')->run();

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('news', 0);
    }

    /**
     * Même garde-fou que pour les événements scrapés (voir
     * NewsletterDraftObserver) — une actu scrapée ne doit jamais générer de
     * brouillon INDIVIDUEL (spam potentiel, plusieurs par jour), seulement
     * le digest groupé de ScrapingDigestBuilder.
     */
    public function test_scraped_articles_never_generate_an_individual_newsletter_draft(): void
    {
        NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $this->makeSource();

        Http::fake([
            '*lejournaltoulousain.fr*' => Http::response($this->feed([
                $this->item('https://www.lejournaltoulousain.fr/toulouse/un-article-404526/', 'Un article'),
            ])),
        ]);

        $this->artisan('scrape:news')->run();

        $this->assertSame(0, Newsletter::where('trigger_type', 'news_published')->count());
        $this->assertSame(1, Newsletter::where('trigger_type', 'scraping_digest')->count());
    }
}
