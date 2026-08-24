<?php

namespace App\Services\Scraping\Cinema;

use App\Models\Cinema;
use App\Models\Language;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\ScreeningTime;
use App\Models\ScreeningType;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Réécriture de `CinemaController::autoUpdateCinemaAllocine` +
 * `autoUpdateCinemaAllocineLiens`/`Liens2` (legacy réel — voir
 * old/backEnd/app/Http/Controllers/CinemaController.php lignes ~1214-1745).
 *
 * IMPORTANT — correction d'audit : la Phase 8 initiale avait reconstruit
 * `PatheGaumontDriver` autour de `CinemaController::autoUpdateCinema`, une
 * fonction qui **n'est référencée par aucune route** dans
 * `old/backEnd/routes/api.php` — donc jamais appelée par le cron en
 * production (code mort). Le vrai mécanisme, confirmé à la fois par le
 * client (cron réel : `wget .../api/autoUpdateCinemaAllocine/{id}` et
 * `.../api/autoUpdateCinemaAllocineLiens/{id}`, un `id` = une ligne
 * `t_cine`) et par la lecture du contrôleur, est un scraping de l'API JSON
 * interne d'AlloCiné, salle par salle — **quasiment toutes les salles**
 * (24 sur 27 lignes actives de `t_cine`, dont Pathé-Gaumont Wilson qui n'a
 * donc pas de traitement spécifique) fonctionnent ainsi, identifiées par
 * l'identifiant AlloCiné encodé dans `t_cine.url`
 * (`salle_gen_csalle=P0057.html` → `P0057`). `PatheGaumontDriver` a été
 * retiré (voir TECHNICAL_DOCUMENTATION.md §13).
 *
 * Source réelle : `GET https://www.allocine.fr/_/showtimes/theater-{id}/d-{Y-m-d}/`
 * — endpoint JSON non documenté publiquement (API interne du site grand
 * public AlloCiné), confirmé par le code du contrôleur legacy réel.
 * Non re-vérifié en direct (sandbox de dev sans accès sortant vers ce
 * domaine) — à confirmer au premier run en environnement de production.
 *
 * Ce que fournit chaque entrée `results[]` de la réponse :
 *   - `movie` : fiche film complète (titre, `internalId`, genres, synopsis,
 *     affiche, casting, réalisateur, année de production) ;
 *   - `showtimes` : objet dont chaque valeur est un tableau de séances
 *     (`diffusionVersion`, `startsAt`, `data.ticketing[]` pour le lien de
 *     réservation) — les clés (dubbed/original/local/multiple...) ne sont
 *     pas fiables/stables, on itère donc les valeurs génériquement, comme
 *     le fait la version courante (non dépréciée) du contrôleur legacy.
 *
 * Corrections et simplifications apportées par rapport au legacy (brief
 * §9 — corriger les bugs, robustesse) :
 *   - **Fenêtre de programmation stable** : le legacy recalculait
 *     `start_date` = date du jour d'exécution du cron (variable nommée
 *     `$dateMercredi` mais qui, à cause d'un idiome PHP
 *     (`strtotime("$jourDeAujourdhui this week")`), valait en réalité
 *     "aujourd'hui", pas le mercredi de la semaine de programmation). Sur
 *     un cron quotidien, cela crée une nouvelle ligne `t_cine_projection`
 *     chaque jour au lieu de mettre à jour la même semaine — doublons.
 *     Ici, la fenêtre [mercredi de la semaine en cours → mardi suivant]
 *     est calculée une seule fois par exécution, indépendamment du jour
 *     où le cron tourne, et sert de clé stable de dédoublonnage.
 *   - **Fusion des 3 endpoints legacy en un seul passage idempotent** :
 *     `autoUpdateCinemaAllocine` (films + horaires, MAIS n'insère le lien
 *     de réservation qu'à la création d'un horaire, jamais en mise à
 *     jour — `checkOrInsertJoursHeures` ne touche `lien_resa` que sur
 *     INSERT) nécessitait un second mécanisme, `autoUpdateCinemaAllocineLiens`
 *     + `Liens2`, avec une table de staging (`t_scrapping_tmp`) et un
 *     traitement par lots de 50, uniquement pour rafraîchir les liens de
 *     réservation publiés après coup. Ici, `ScreeningTime::updateOrCreate`
 *     réécrit systématiquement `booking_url`, même sur une ligne
 *     existante : un seul passage journalier suffit, plus de staging.
 *   - **Dédoublonnage des films par identifiant AlloCiné** (`internalId`,
 *     stocké dans `movies.external_ref`) au lieu d'un slug recalculé sur
 *     le titre (`t_cine_film` n'avait pas de colonne d'identifiant externe
 *     stable). Les ~17 300 films déjà migrés depuis `t_cine_film`
 *     n'ont pas cet identifiant : un repli best-effort tente un match par
 *     slug existant (`Str::slug($titre)`) avant de créer un doublon, et
 *     rétro-remplit `external_ref` dessus. Ce repli n'est pas garanti à
 *     100 % (l'algorithme de slug legacy diffère marginalement de
 *     `Str::slug()` sur certains caractères) — à surveiller après le
 *     premier run réel.
 *   - Réalisateur : recherche dans `credits[]` une personne dont le poste
 *     contient "réalisation" avant de retomber sur `credits[0]` (le
 *     legacy prenait `credits[0]` sans condition, en supposant l'ordre).
 *   - Toutes les valeurs de genre sont concaténées (le legacy ne gardait
 *     que `genres[0]`).
 *   - Aucune pagination (`p-{n}`) n'est appliquée : le contrôleur legacy
 *     actif (`autoUpdateCinemaAllocine`) n'en faisait pas non plus pour
 *     cette route (seule la variante `...Liens` paginait, pour un usage
 *     différent). Une salle publiant plus de séances que n'en renvoie une
 *     seule page pourrait donc manquer des horaires tardifs — limite
 *     héritée du legacy, à surveiller au premier run réel, pas une
 *     régression introduite ici.
 *   - Ne reproduit PAS le lien "bande-annonce" du legacy
 *     (`inserGaumontWilsonFilm`/`getIdFilm` construisaient
 *     `https://www.allocine.fr/video/player_gen_cmedia=20601090&cfilm=...`
 *     avec un identifiant `cmedia` codé en dur, identique pour tous les
 *     films — manifestement un bug de copier-coller, pas une vraie
 *     bande-annonce par film). Le vrai scraping de bande-annonce
 *     (`autoUpdateCinemaAllocineBA`, scraping HTML de la fiche AlloCiné)
 *     est une fonctionnalité distincte, non reprise dans cette phase.
 */
class AllocineDriver implements ScraperDriver
{
    /** legacy_id (t_cine_lang.id) par diffusionVersion AlloCiné. */
    private const LANGUAGE_MAP = [
        'ORIGINAL' => ['legacy_id' => 4, 'name' => 'VO'],
        'LOCAL' => ['legacy_id' => 2, 'name' => 'VF'],
        'DUBBED' => ['legacy_id' => 2, 'name' => 'VF'],
    ];

    private const UNKNOWN_LANGUAGE = ['legacy_id' => 1, 'name' => 'Inconnue'];

    /** t_cine_type_projection.id = 4 ("Numerique"), codé en dur dans le legacy pour toute séance issue d'AlloCiné. */
    private const DEFAULT_SCREENING_TYPE = ['legacy_id' => 4, 'name' => 'Numerique'];

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $theaterId = $config['allocine_theater_id'] ?? null;
        $cinemaId = $config['cinema_id'] ?? null;
        $windowDays = (int) ($config['window_days'] ?? 7);

        if (! $theaterId || ! $cinemaId) {
            throw new \RuntimeException('Configuration invalide : allocine_theater_id et cinema_id sont requis dans ScraperSource.config.');
        }

        $cinema = Cinema::find($cinemaId);
        if (! $cinema) {
            throw new \RuntimeException("Cinéma introuvable (id={$cinemaId}).");
        }

        [$weekStart, $weekEnd] = $this->currentProgrammingWeek();

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        $seenMovies = [];
        $daysFailed = 0;

        for ($offset = 0; $offset < max(1, $windowDays); $offset++) {
            $date = now()->addDays($offset)->toDateString();
            $results = $this->fetchDay($theaterId, $date);

            if ($results === null) {
                $daysFailed++;

                continue;
            }

            foreach ($results as $entry) {
                $entry = (array) $entry;
                $movieData = isset($entry['movie']) ? (array) $entry['movie'] : null;

                if (! $movieData) {
                    $stats['skipped']++;

                    continue;
                }

                $externalRef = isset($movieData['internalId']) ? (string) $movieData['internalId'] : null;
                if (! $externalRef) {
                    $stats['skipped']++;

                    continue;
                }

                if (! isset($seenMovies[$externalRef])) {
                    $seenMovies[$externalRef] = true;
                    $stats['found']++;
                    $created = $this->upsertMovie($externalRef, $movieData);
                    $created ? $stats['created']++ : $stats['updated']++;
                }

                $movie = Movie::where('external_ref', $externalRef)->first();
                if (! $movie) {
                    $stats['skipped']++;

                    continue;
                }

                $this->syncShowtimes($cinema, $movie, (array) ($entry['showtimes'] ?? []), $weekStart, $weekEnd);
            }
        }

        if ($daysFailed >= max(1, $windowDays)) {
            throw new \RuntimeException("Échec de récupération des séances pour la salle {$cinema->name} (toutes les journées de la fenêtre ont échoué).");
        }

        return $stats;
    }

    /**
     * @return array<int, mixed>|null null si la récupération de la journée a échoué (log + run continue).
     */
    protected function fetchDay(string $theaterId, string $date): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept' => 'application/json',
            ])->timeout(20)->retry(2, 500)
                ->get("https://www.allocine.fr/_/showtimes/theater-{$theaterId}/d-{$date}/");

            if ($response->failed()) {
                Log::channel('single')->warning("scrape:cinema (Allociné) — HTTP {$response->status()} pour theater={$theaterId} date={$date}.");

                return null;
            }

            return (array) $response->json('results', []);
        } catch (\Throwable $e) {
            Log::channel('single')->warning("scrape:cinema (Allociné) — erreur réseau theater={$theaterId} date={$date} : {$e->getMessage()}");

            return null;
        }
    }

    /** @return bool true si le film a été créé, false s'il a été mis à jour. */
    protected function upsertMovie(string $externalRef, array $movieData): bool
    {
        $title = $movieData['title'] ?? $externalRef;
        $attributes = $this->mapMovieAttributes($movieData);

        $movie = Movie::where('external_ref', $externalRef)->first();

        if (! $movie) {
            // Repli best-effort pour les films déjà migrés depuis t_cine_film (pas d'external_ref legacy).
            $movie = Movie::where('slug', Str::slug($title))->whereNull('external_ref')->first();
        }

        if ($movie) {
            $movie->fill(array_merge($attributes, ['external_ref' => $externalRef]));
            $movie->save();

            return false;
        }

        Movie::create(array_merge($attributes, ['title' => $title, 'external_ref' => $externalRef]));

        return true;
    }

    protected function mapMovieAttributes(array $movieData): array
    {
        $genres = collect($movieData['genres'] ?? [])->pluck('translate')->filter()->implode(', ');

        $director = null;
        $credits = collect($movieData['credits'] ?? []);
        $directorCredit = $credits->first(function ($credit) {
            $position = strtolower((string) (data_get($credit, 'position.name') ?? ''));

            return str_contains($position, 'réal') || str_contains($position, 'real');
        }) ?? $credits->first();

        if ($directorCredit) {
            $director = trim((data_get($directorCredit, 'person.firstName') ?? '').' '.(data_get($directorCredit, 'person.lastName') ?? ''));
            $director = $director !== '' ? $director : null;
        }

        $names = collect(data_get($movieData, 'cast.edges', []))
            ->flatMap(function ($edge) {
                $node = (array) data_get($edge, 'node', []);

                return collect(['actor', 'voiceActor', 'originalVoiceActor'])
                    ->map(fn ($key) => isset($node[$key])
                        ? trim((data_get($node[$key], 'firstName') ?? '').' '.(data_get($node[$key], 'lastName') ?? ''))
                        : null)
                    ->filter();
            })
            ->filter()
            ->implode(', ');

        $productionYear = data_get($movieData, 'data.productionYear');

        return array_filter([
            'title' => $movieData['title'] ?? null,
            'director' => $director,
            'cast' => $names !== '' ? $names : null,
            'genres' => $genres !== '' ? $genres : null,
            'synopsis' => $movieData['synopsis'] ?? null,
            'poster' => data_get($movieData, 'poster.url'),
            'release_date' => is_numeric($productionYear) ? "{$productionYear}-01-01" : null,
        ], fn ($value) => $value !== null);
    }

    protected function syncShowtimes(Cinema $cinema, Movie $movie, array $showtimesGroups, Carbon $weekStart, Carbon $weekEnd): void
    {
        $screeningType = ScreeningType::firstOrCreate(
            ['legacy_id' => self::DEFAULT_SCREENING_TYPE['legacy_id']],
            ['name' => self::DEFAULT_SCREENING_TYPE['name']]
        );

        foreach ($showtimesGroups as $sessions) {
            foreach ((array) $sessions as $proj) {
                $proj = (array) $proj;

                if (empty($proj['startsAt'])) {
                    continue;
                }

                try {
                    $startsAt = Carbon::parse($proj['startsAt']);
                } catch (\Throwable) {
                    continue;
                }

                $languageDef = self::LANGUAGE_MAP[$proj['diffusionVersion'] ?? ''] ?? self::UNKNOWN_LANGUAGE;
                $language = Language::firstOrCreate(
                    ['legacy_id' => $languageDef['legacy_id']],
                    ['name' => $languageDef['name']]
                );

                $screening = Screening::updateOrCreate(
                    [
                        'cinema_id' => $cinema->id,
                        'movie_id' => $movie->id,
                        'language_id' => $language->id,
                        'start_date' => $weekStart->toDateString(),
                    ],
                    ['end_date' => $weekEnd->toDateString()]
                );

                $screening->types()->syncWithoutDetaching([$screeningType->id]);

                ScreeningTime::updateOrCreate(
                    [
                        'screening_id' => $screening->id,
                        'weekday' => $startsAt->dayOfWeek,
                        'time' => $startsAt->format('H:i'),
                    ],
                    ['booking_url' => $this->extractBookingUrl($proj)]
                );
            }
        }
    }

    protected function extractBookingUrl(array $proj): ?string
    {
        $ticketing = collect(data_get($proj, 'data.ticketing', []))
            ->first(fn ($t) => (data_get($t, 'provider') ?? null) === 'default');

        return data_get($ticketing, 'urls.0');
    }

    /**
     * Fenêtre de programmation stable [mercredi de la semaine en cours → mardi suivant],
     * calculée une seule fois par exécution — voir docblock de la classe.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function currentProgrammingWeek(): array
    {
        $now = now()->startOfDay();
        $diffFromWednesday = ($now->dayOfWeekIso - Carbon::WEDNESDAY + 7) % 7;
        $weekStart = $now->copy()->subDays($diffFromWednesday);
        $weekEnd = $weekStart->copy()->addDays(6);

        return [$weekStart, $weekEnd];
    }
}
