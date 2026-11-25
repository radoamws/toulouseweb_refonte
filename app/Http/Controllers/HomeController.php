<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Page;
use App\Models\Slider;
use App\Models\TransportLine;
use App\Services\Weather\OpenMeteoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Homepage repensée (brief §4) : slider administrable, contenus à la une par
 * domaine (actualités, agenda, cinéma, annuaire, annonces), catégories
 * importantes. Conçue pour maximiser navigation/découverte plutôt que
 * reproduire l'ancienne page 3-colonnes (voir TECHNICAL_DOCUMENTATION.md §6).
 */
class HomeController extends Controller
{
    public function index(Request $request, OpenMeteoService $weather): View
    {
        $page = Page::where('key', 'home')->first();

        // Vue de page (demande client, 15/09/2026, voir §44) — pas d'entité
        // précise pour la home.
        $this->recordPageView($request);

        return view('home', [
            'seo' => $page?->resolveSeo() ?? [],
            'slides' => Slider::activeOn('home')->with('placements')->get(),
            // Météo + qualité de l'air (brief roadmap "contenu", 14/10/2026,
            // demande client) — widget purement informatif, `null` si
            // Open-Meteo est indisponible (voir OpenMeteoService::current()),
            // jamais d'erreur remontée à la home pour ça.
            'weather' => $weather->current(),
            // Tri par date de publication la plus récente (demande client,
            // 12/09/2026) — `COALESCE` plutôt qu'un simple `latest('published_at')` :
            // un article publié sans `published_at` renseigné (rare, 1/234
            // en production) remonterait sinon systématiquement en tête de
            // liste (NULL trié comme la plus petite valeur en DESC sur
            // MySQL), peu importe son ancienneté réelle — repli sur
            // `created_at` dans ce cas précis.
            // Demande client (20/11/2026, voir §82) : "les news ne sont pas
            // à jour" + "prioriser les news publiés manuellement... et
            // validés" + "n'affichera que les news supérieures à la date
            // du jour". Une actu déjà publiée n'a jamais de date FUTURE —
            // confirmé avec le client qu'il s'agit en fait de fraîcheur
            // (publiée aujourd'hui/récemment), pas d'un filtre "date >
            // maintenant" qui viderait systématiquement la section.
            //
            // ⚠️ Revu deux fois suite aux remarques du client :
            // 1. (23/11/2026, 1er retour) une fenêtre stricte de 24h
            //    (`WHERE ... >= now()->subDay()`) pouvait, un jour où Le
            //    Journal Toulousain ne publie rien de nouveau, laisser la
            //    section avec MOINS de 4 actus voire totalement vide —
            //    "affiche toujours les 4 derniers news". Retrait du `WHERE`
            //    d'exclusion.
            // 2. (23/11/2026, 2e retour) mais passer alors à un tri
            //    `source != 'scraped'` INCONDITIONNEL faisait remonter de
            //    très anciennes actus manuelles/legacy (parfois plusieurs
            //    mois) devant des actus scrapées bien plus récentes — "tu as
            //    mis des très anciens news... il faut toujours que ça soit
            //    les derniers, scraping ou pas confondus". La priorité
            //    source ne doit donc jouer QUE dans une fenêtre de fraîcheur
            //    bornée (4 jours, "remettre les scrapings auto sur les 4
            //    derniers jours s'il n'y a pas le jour J") ; au-delà, seule
            //    la date compte, tous types confondus — jamais une actu
            //    ancienne artificiellement promue juste parce qu'elle n'est
            //    pas scrapée.
            //
            // 3 paliers (`CASE`, du plus prioritaire au moins prioritaire) :
            //   0. manuelle/soumise-validée ET publiée dans les 4 derniers jours
            //   1. scrapée ET publiée dans les 4 derniers jours
            //   2. tout le reste (plus ancien que 4 jours, scraping ou pas
            //      confondus) — jamais exclu (toujours 4 actus affichées),
            //      juste trié par fraîcheur réelle, sans biais de source.
            'latestNews' => News::query()->published()
                ->orderByRaw(
                    "CASE
                        WHEN COALESCE(published_at, created_at) >= ? AND source != 'scraped' THEN 0
                        WHEN COALESCE(published_at, created_at) >= ? THEN 1
                        ELSE 2
                    END ASC",
                    [now()->subDays(4), now()->subDays(4)]
                )
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->limit(4)->get(),
            // ⚠️ Bug réel trouvé et corrigé (20/11/2026, signalé par le
            // client : "Agenda & sorties" doit afficher des dates
            // supérieures à aujourd'hui) — confirmé en direct en production :
            // la section affichait "Obstinées" daté du 03 août 2026 alors
            // qu'on était le 20 novembre. `Event::scopeUpcoming()` (utilisé
            // tel quel sur /agenda) filtre correctement sur `end_date`, pas
            // sur `start_date` — un spectacle à residence longue durée
            // ("Obstinées", `start_date` = 03/08/2026, `end_date` =
            // 05/12/2026) y reste donc légitimement "à venir" tant que sa
            // dernière date n'est pas passée. Problème spécifique à CETTE
            // vignette d'accueil : `orderBy('start_date')` ASC fait
            // remonter en premier le spectacle qui a commencé le PLUS TÔT
            // (donc le plus ancien), avant les événements qui n'ont pas
            // encore commencé — l'inverse de l'effet "nouveauté" recherché
            // ici. `where('start_date', '>=', now())` restreint CETTE
            // requête aux événements pas encore commencés (947 en
            // production au moment du correctif, largement suffisant) —
            // volontairement PAS un changement de `scopeUpcoming()` lui-même
            // (/agenda doit continuer à lister aussi les spectacles en
            // cours de residence, eux bien "à venir" pour qui n'y est pas
            // encore allé).
            'upcomingEvents' => Event::query()->published()->upcoming()->where('start_date', '>=', now())
                ->with(['area', 'categories'])
                ->orderBy('start_date')->limit(4)->get(),
            // `premium_until > now()` en plus de `tier = 'paid'` (rectification
            // client, 25/11/2026 — l'abonnement payant est annuel, pas
            // définitif, voir Listing::isPaid()/TECHNICAL_DOCUMENTATION.md
            // §83) : une fiche payante dont l'abonnement a expiré sans
            // renouvellement ne doit plus être mise en avant ici.
            'featuredListings' => Listing::query()->where('status', 'published')->where('tier', 'paid')
                ->where('premium_until', '>', now())
                ->with('categories')->latest()->limit(6)->get(),
            // ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit UI/UX) :
            // `whereHas('screenings')` sans filtre acceptait n'importe quel
            // film ayant EU une séance un jour, même terminée depuis des
            // années — vérifié en direct : la home proposait "The Fabelmans"
            // (2022) alors que /cinema affichait déjà "Aucun film à
            // l'affiche". Même scope que CinemaController::index() (déjà
            // correct), pour ne jamais promouvoir un film qui ne joue plus.
            'latestMovies' => Movie::query()
                ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
                ->latest('release_date')->limit(6)->get(),
            'latestClassifieds' => Classified::query()->where('status', 'published')->latest()->limit(4)->get(),
            'topCategories' => Category::query()->where('level', 0)->where('is_active', true)
                ->orderBy('order')->limit(8)->get(),
            // Carrousel transports (brief roadmap "contenu", 14/10/2026,
            // demande client) — un slide par mode, compte réel de lignes
            // actives par type plutôt que des noms de lignes en dur (reste
            // juste même si l'import Tisséo change le détail des lignes,
            // voir ImportTisseoGtfs). Pas affiché du tout si aucune ligne
            // n'a encore été importée (pas de bloc vide/trompeur).
            'transportLineCounts' => TransportLine::where('is_active', true)
                ->selectRaw('type, count(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type'),
        ]);
    }
}
