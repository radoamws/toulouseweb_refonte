<?php

namespace App\Services\Scraping\Cinema;

use App\Models\Movie;
use App\Models\Screening;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Réécriture de `CinemaController::autoUpdateCinema` (legacy, cassé — voir
 * TECHNICAL_DOCUMENTATION.md §3.3 de l'audit backend : l'INSERT final était
 * commenté, rien n'était réellement importé). Source : API JSON publique
 * Pathé-Gaumont, confirmée en lisant le code legacy réel (le sandbox de
 * développement n'a pas d'accès sortant vers cette API — bloquée par leur
 * pare-feu Akamai — donc **non re-vérifiée en direct**, contrairement au
 * reste du projet ; à confirmer dès la première exécution en environnement
 * avec accès réseau sortant).
 *
 * IMPORTANT — portée assumée, fidèle à ce que le legacy faisait réellement :
 * cette API ne fournit PAS d'horaires de séance exploitables (le code
 * legacy les ignorait déjà, il ne s'en servait que pour savoir SI un film
 * était programmé sur la fenêtre de dates). Ce driver :
 *   - découvre/actualise les FICHES FILM (titre, réalisateur, casting,
 *     genres, durée, synopsis, affiche, distributeur, année) ;
 *   - crée/mets à jour une association salle+film (`screenings`, fenêtre de
 *     validité) pour marquer le film comme "à l'affiche" dans cette salle ;
 *   - ne renseigne PAS `screening_times` (horaires précis) — ceux-ci
 *     restent une saisie manuelle admin, exactement comme dans le legacy
 *     (`CinemaController::addUpdateProj`, jamais remplacé par du scraping).
 *
 * Améliorations par rapport au legacy (brief §9 : "corrige les bugs,
 * améliore la robustesse") :
 *   - dédoublonnage fiable par `external_ref` (slug) au lieu d'un test
 *     d'existence bugué (`getFilmDetailBySlug` avant insertion, qui
 *     comparait par nom/slug texte sans jamais mettre à jour l'existant) ;
 *   - les fiches déjà connues sont désormais MISES À JOUR (synopsis/
 *     affiche/casting peuvent changer), pas seulement créées une fois ;
 *   - le bug de filtrage de dates du legacy (comparaison à la même borne
 *     deux fois : `$dateSemFin >= $dtDate && $dtDate <= $dateSemFin`) est
 *     corrigé ici en comparant réellement à la fenêtre [début, fin] ;
 *   - chaque appel HTTP est protégé (timeout, retry, échec isolé par film
 *     plutôt que d'interrompre tout le run) ;
 *   - honore `ScraperSource.config` (aucune salle ne doit plus être codée
 *     en dur comme "gaumont wilson" l'était dans le contrôleur legacy).
 */
class PatheGaumontDriver implements ScraperDriver
{
    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $cinemaApiSlug = $config['cinema_api_slug'] ?? null;
        $cinemaId = $config['cinema_id'] ?? null;
        $windowDays = $config['window_days'] ?? 7;

        if (! $cinemaApiSlug || ! $cinemaId) {
            throw new \RuntimeException('Configuration invalide : cinema_api_slug et cinema_id sont requis dans ScraperSource.config.');
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $windowStart = now()->startOfDay();
        $windowEnd = now()->addDays($windowDays)->endOfDay();

        $shows = $this->fetchShowsList($cinemaApiSlug);

        foreach ($shows as $slug => $show) {
            $stats['found']++;

            $days = collect((array) ($show['days'] ?? []))
                ->keys()
                ->map(fn ($date) => $this->safeParseDate($date))
                ->filter()
                ->filter(fn (Carbon $date) => $date->betweenIncluded($windowStart, $windowEnd));

            if ($days->isEmpty()) {
                $stats['skipped']++;

                continue;
            }

            $detail = $this->fetchShowDetail($slug);
            if (! $detail) {
                Log::channel('single')->warning("scrape:cinema — détail introuvable pour le film legacy slug={$slug}, ignoré.");
                $stats['skipped']++;

                continue;
            }

            $existing = Movie::where('external_ref', $slug)->exists();

            $movie = Movie::updateOrCreate(
                ['external_ref' => $slug],
                $this->mapMovieAttributes($slug, $detail)
            );

            $existing ? $stats['updated']++ : $stats['created']++;

            Screening::updateOrCreate(
                ['cinema_id' => $cinemaId, 'movie_id' => $movie->id, 'language_id' => null],
                ['start_date' => $days->min()->toDateString(), 'end_date' => $days->max()->toDateString()]
            );
        }

        return $stats;
    }

    protected function fetchShowsList(string $cinemaApiSlug): array
    {
        $response = Http::timeout(20)->retry(2, 500)
            ->get("https://www.cinemaspathegaumont.com/api/cinema/{$cinemaApiSlug}/shows", ['language' => 'fr']);

        if ($response->failed()) {
            throw new \RuntimeException("Échec de récupération de la liste des films (HTTP {$response->status()}).");
        }

        return (array) $response->json('shows', []);
    }

    protected function fetchShowDetail(string $slug): ?array
    {
        try {
            $response = Http::timeout(20)->retry(2, 500)
                ->get("https://www.cinemaspathegaumont.com/api/show/{$slug}", ['language' => 'fr']);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::channel('single')->warning("scrape:cinema — erreur réseau sur le détail du film {$slug} : {$e->getMessage()}");

            return null;
        }
    }

    protected function mapMovieAttributes(string $slug, array $detail): array
    {
        $durationMinutes = isset($detail['duration']) ? (int) $detail['duration'] : null;

        return array_filter([
            'title' => $detail['title'] ?? $slug,
            'director' => $detail['directors'] ?? null,
            'cast' => $detail['actors'] ?? null,
            'genres' => isset($detail['genres']) ? implode(', ', (array) $detail['genres']) : null,
            'duration_minutes' => $durationMinutes,
            'synopsis' => $detail['synopsis'] ?? null,
            'poster' => $detail['posterPath']['md'] ?? $detail['posterPath']['lg'] ?? null,
            'distributor' => $detail['distribution'] ?? null,
            'release_date' => isset($detail['releaseAt'][0]) ? $this->safeParseDate($detail['releaseAt'][0])?->toDateString() : null,
        ], fn ($value) => $value !== null);
    }

    protected function safeParseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
