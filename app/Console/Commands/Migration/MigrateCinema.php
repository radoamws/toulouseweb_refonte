<?php

namespace App\Console\Commands\Migration;

use App\Models\Cinema;
use App\Models\Language;
use App\Models\Movie;
use App\Models\MovieComment;
use App\Models\Screening;
use App\Models\ScreeningType;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 4 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : cinéma.
 * Domaine le mieux modélisé du legacy (voir audit DB §3), migré tel quel une
 * fois assaini. Les tables `_bkp` ne sont volontairement PAS lues (données
 * mortes, voir audit §3.2). Nécessite `migrate:reference-data` avant
 * (languages, screening_types).
 *
 * NB : `t_cine_categ` ("Toulouse et complexes" / "Toiles de banlieue") n'est
 * pas repris — distinction jugée sans valeur suffisante pour justifier une
 * colonne dédiée dans le nouveau schéma (à revoir si le client la juge utile).
 */
class MigrateCinema extends Command
{
    protected $signature = 'migrate:cinema';

    protected $description = 'Migre salles, films, séances, horaires et commentaires cinéma depuis toulouseweb_old';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $cinemaMap = $this->migrateCinemas();
        $movieMap = $this->migrateMovies();
        $this->migrateScreenings($cinemaMap, $movieMap);
        $this->migrateComments($movieMap);

        return self::SUCCESS;
    }

    protected function migrateCinemas(): \Illuminate\Support\Collection
    {
        $log = new MigrationLog('cinema-cinemas');

        Cinema::withoutEvents(function () use ($log) {
            foreach (DB::connection('legacy')->table('t_cine')->orderBy('id')->get() as $row) {
                $name = LegacyCleaner::text($row->nom) ?? "Salle #{$row->id}";
                $existing = Cinema::where('legacy_id', $row->id)->first();
                $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug ?: $row->slug_old, $name, 'cinemas', $existing?->id);

                $address = trim(collect([$row->adresse, $row->cp, $row->ville])->filter()->implode(' '));

                $lat = self::validCoordinate($row->lat, 90);
                $lng = self::validCoordinate($row->lon, 180);
                if ($row->lon && $lng === null) {
                    $log->warn("Salle legacy #{$row->id} ({$name}) : longitude invalide ({$row->lon}) ignorée.");
                }

                $cinema = Cinema::updateOrCreate(
                    ['legacy_id' => $row->id],
                    [
                        'name' => $name,
                        'slug' => $slug,
                        'address' => LegacyCleaner::text($address),
                        'lat' => $lat,
                        'lng' => $lng,
                        'external_url' => LegacyCleaner::text($row->url),
                        'is_active' => (int) $row->status === 1,
                    ]
                );
                $existing ? $log->updated("#{$row->id} -> #{$cinema->id} ({$name})") : $log->created("#{$row->id} -> #{$cinema->id} ({$name})");
            }
        });

        $this->info($log->summary());

        return Cinema::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
    }

    protected function migrateMovies(): \Illuminate\Support\Collection
    {
        $log = new MigrationLog('cinema-movies');

        Movie::withoutEvents(function () use ($log) {
            DB::connection('legacy')->table('t_cine_film')->orderBy('id')->chunk(500, function ($rows) use ($log) {
                foreach ($rows as $row) {
                    $title = LegacyCleaner::text($row->titre);
                    if (! $title) {
                        $log->skipped("Film legacy #{$row->id} sans titre — ignoré.");

                        continue;
                    }

                    $existing = Movie::where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug ?: $row->slug_old, $title, 'movies', $existing?->id);

                    $movie = Movie::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'title' => $title,
                            'slug' => $slug,
                            'director' => LegacyCleaner::text($row->realisateur),
                            'cast' => LegacyCleaner::text($row->acteurs),
                            'genres' => LegacyCleaner::text($row->genres),
                            'duration_minutes' => self::durationToMinutes($row->duree),
                            'synopsis' => LegacyCleaner::text($row->synopsis),
                            'poster' => LegacyCleaner::text($row->image),
                            'distributor' => LegacyCleaner::text($row->distributeur),
                            'release_date' => LegacyCleaner::date($row->date_sortie),
                        ]
                    );
                    $existing ? $log->updated("#{$row->id} -> #{$movie->id} ({$title})") : $log->created("#{$row->id} -> #{$movie->id} ({$title})");
                }
            });
        });

        $this->info($log->summary());

        return Movie::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
    }

    protected function migrateScreenings(\Illuminate\Support\Collection $cinemaMap, \Illuminate\Support\Collection $movieMap): void
    {
        $log = new MigrationLog('cinema-screenings');
        $languageMap = Language::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $typeMap = ScreeningType::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $projTypes = DB::connection('legacy')->table('t_cine_proj_types')->get()->groupBy('id_proj');
        $screeningIdByLegacyId = [];

        Screening::withoutEvents(function () use ($log, $cinemaMap, $movieMap, $languageMap, $typeMap, $projTypes, &$screeningIdByLegacyId) {
            DB::connection('legacy')->table('t_cine_projection')->orderBy('id')->chunk(500, function ($rows) use ($log, $cinemaMap, $movieMap, $languageMap, $typeMap, $projTypes, &$screeningIdByLegacyId) {
                foreach ($rows as $row) {
                    $cinemaId = $cinemaMap[$row->id_cine] ?? null;
                    $movieId = $movieMap[$row->id_cine_film] ?? null;
                    if (! $cinemaId || ! $movieId) {
                        $log->skipped("Séance legacy #{$row->id} : salle ou film introuvable (cine={$row->id_cine}, film={$row->id_cine_film}).");

                        continue;
                    }

                    $screening = Screening::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'cinema_id' => $cinemaId,
                            'movie_id' => $movieId,
                            'language_id' => $languageMap[$row->id_cine_lang] ?? null,
                            'start_date' => LegacyCleaner::date($row->start_date),
                            'end_date' => LegacyCleaner::date($row->end_date),
                            'preview' => (bool) $row->avant_premiere,
                            'staff_pick' => false, // colonne coup_coeur absente de ce dump legacy
                        ]
                    );
                    $screeningIdByLegacyId[$row->id] = $screening->id;
                    $screening->wasRecentlyCreated ? $log->created("#{$row->id} -> #{$screening->id}") : $log->updated("#{$row->id} -> #{$screening->id}");

                    $typeIds = ($projTypes[$row->id] ?? collect())
                        ->map(fn ($r) => $typeMap[$r->id_type] ?? null)->filter()->values();
                    $screening->types()->sync($typeIds);
                }
            });
        });
        $this->info($log->summary());

        $this->migrateScreeningTimes($screeningIdByLegacyId);
    }

    protected function migrateScreeningTimes(array $screeningIdByLegacyId): void
    {
        $log = new MigrationLog('cinema-screening-times');

        // Chargé une fois (jusqu'à ~300k entiers, largement gérable en
        // mémoire) plutôt qu'une requête EXISTS par ligne — indispensable
        // sur ce volume (voir note de robustesse ci-dessous).
        $existingLegacyIds = \App\Models\ScreeningTime::whereNotNull('legacy_id')->pluck('legacy_id')->flip();

        DB::connection('legacy')->table('t_cine_proj_heures')->orderBy('id')->chunk(2000, function ($rows) use ($log, $screeningIdByLegacyId, $existingLegacyIds) {
            $inserts = [];
            foreach ($rows as $row) {
                $screeningId = $screeningIdByLegacyId[$row->id_cine_proj] ?? null;
                if (! $screeningId) {
                    $log->skipped("Horaire legacy #{$row->id} : séance legacy #{$row->id_cine_proj} introuvable.");

                    continue;
                }
                if (isset($existingLegacyIds[$row->id])) {
                    continue;
                }
                $inserts[] = [
                    'screening_id' => $screeningId,
                    'weekday' => (int) $row->jour,
                    'time' => LegacyCleaner::text($row->heure),
                    'booking_url' => LegacyCleaner::text($row->lien_resa),
                    'legacy_id' => $row->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($inserts) {
                \App\Models\ScreeningTime::insert($inserts);
                $log->createdMany(count($inserts), count($inserts).' horaires insérés (lot).');
            }
        });

        $this->info($log->summary());
    }

    protected function migrateComments(\Illuminate\Support\Collection $movieMap): void
    {
        $log = new MigrationLog('cinema-comments');

        foreach (DB::connection('legacy')->table('t_cine_comment')->orderBy('id')->get() as $row) {
            $movieId = $movieMap[$row->id_cine_film] ?? null;
            if (! $movieId) {
                $log->skipped("Commentaire legacy #{$row->id} : film introuvable.");

                continue;
            }
            $existing = MovieComment::where('legacy_id', $row->id)->first();
            $comment = MovieComment::updateOrCreate(
                ['legacy_id' => $row->id],
                [
                    'movie_id' => $movieId,
                    'author_name' => LegacyCleaner::text($row->pseudo) ?? 'Anonyme',
                    'body' => LegacyCleaner::text($row->commentaire) ?? '',
                    'status' => (int) $row->status === 1 ? 'published' : 'pending',
                ]
            );
            $existing ? $log->updated("#{$row->id} -> #{$comment->id}") : $log->created("#{$row->id} -> #{$comment->id}");
        }

        $this->info($log->summary());
    }

    protected static function validCoordinate(null|int|float $value, float $bound): ?float
    {
        if ($value === null || $value === 0.0 || $value === 0) {
            return null;
        }

        return abs((float) $value) <= $bound ? (float) $value : null;
    }

    protected static function durationToMinutes(?string $time): ?int
    {
        if (! $time) {
            return null;
        }
        [$h, $m] = array_pad(explode(':', $time), 2, 0);

        return ((int) $h * 60) + (int) $m;
    }
}
