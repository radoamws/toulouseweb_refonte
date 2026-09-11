<?php

namespace App\Console\Commands\Migration;

use App\Models\Slider;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 6 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : sliders
 * homepage/pages (t_sliders -> sliders) + emplacements (t_slider_place).
 */
class MigrateSliders extends Command
{
    protected $signature = 'migrate:sliders';

    protected $description = 'Migre les sliders et leurs emplacements depuis toulouseweb_old';

    /**
     * Normalise les noms de page legacy (français/abrégés) vers la
     * convention du nouveau site (voir resources/views/home.blade.php et
     * SliderResource). `rencontres` (module archivé, non reconstruit) et
     * `billboardG`/`billboardD` (skyscrapers 3-colonnes de l'ancienne
     * homepage, absents du nouveau design) sont volontairement omis : `null`
     * => emplacement ignoré.
     */
    protected const PAGE_MAP = [
        'accueil' => 'home',
        'agenda' => 'agenda',
        'annuaire' => 'annuaire',
        'cinema' => 'cinema',
        'restaurants' => 'restaurants',
        'bannonces' => 'annonces',
        'lanuit' => 'la-nuit',
        'rencontres' => null,
        'billboardG' => null,
        'billboardD' => null,
    ];

    public function handle(): int
    {
        $log = new MigrationLog('sliders');
        // array_key_exists (pas ??) : certaines entrées de PAGE_MAP valent
        // explicitement `null` (emplacement à ignorer) — `??` les aurait
        // traitées comme "non mappées" et aurait laissé passer le nom
        // legacy tel quel, ce qui est le contraire de l'effet recherché.
        $pageNameById = DB::connection('legacy')->table('t_slider_page')->pluck('name', 'id')
            ->map(fn ($name) => array_key_exists($name, self::PAGE_MAP) ? self::PAGE_MAP[$name] : $name);
        $placements = DB::connection('legacy')->table('t_slider_place')->get()->groupBy('id_slider');

        Slider::withoutEvents(function () use ($log, $pageNameById, $placements) {
            foreach (DB::connection('legacy')->table('t_sliders')->orderBy('id')->get() as $row) {
                $title = LegacyCleaner::text($row->nom) ?? LegacyCleaner::text($row->titreBillboard) ?? "Slider #{$row->id}";
                $linkUrl = LegacyCleaner::text($row->urlBillboard);
                $clientName = LegacyCleaner::text($row->client);

                // ⚠️ Bug réel trouvé et corrigé (11/09/2026, demande client) :
                // sur la quasi-totalité des lignes réelles, les opérateurs du
                // back-office legacy ont saisi l'URL cible directement dans
                // `nom` (le champ "titre" affiché sur le slide) au lieu de
                // `urlBillboard` (le vrai champ lien) — laissé vide dans ~98%
                // des cas. Résultat : le slide affichait l'URL en toutes
                // lettres comme titre, et n'était même pas cliquable
                // (`link_url` NULL). Si `nom` ressemble à une URL ET que
                // `urlBillboard` est vide, on la bascule vers `link_url` et
                // on reprend `client` (le vrai nom lisible, ex. "Escale")
                // comme titre — voir aussi `content:fix-slider-links`, qui
                // applique la même correction aux lignes déjà migrées.
                if ($linkUrl === null && $title !== null && preg_match('/^https?:\/\/\S+\.\S+/i', $title)) {
                    $linkUrl = $title;
                    $title = $clientName ?? (parse_url($title, PHP_URL_HOST) ?: "Slider #{$row->id}");
                }

                $image = LegacyCleaner::text($row->img);
                if (! $image) {
                    $log->skipped("Slider legacy #{$row->id} ({$title}) sans image — ignoré.");

                    continue;
                }

                $slider = Slider::updateOrCreate(
                    ['legacy_id' => $row->id],
                    [
                        'title' => $title,
                        'image' => $image,
                        'link_url' => $linkUrl,
                        'client_name' => $clientName,
                        'order' => (int) $row->ordre,
                        'delay_ms' => (int) $row->delai ?: 5000,
                        'starts_at' => LegacyCleaner::date($row->date_debut),
                        'ends_at' => LegacyCleaner::date($row->date_fin),
                        'is_active' => (int) $row->dissimule !== 1,
                    ]
                );
                $slider->wasRecentlyCreated ? $log->created("#{$row->id} -> #{$slider->id} ({$title})") : $log->updated("#{$row->id} -> #{$slider->id} ({$title})");

                $pages = ($placements[$row->id] ?? collect())
                    ->map(fn ($r) => $pageNameById[$r->id_slider_page] ?? null)->filter()->unique()->values();
                $slider->placements()->delete();
                foreach ($pages as $page) {
                    $slider->placements()->create(['page' => $page]);
                }
                if ($pages->isEmpty()) {
                    $log->warn("Slider #{$slider->id} ({$title}) : aucun emplacement de page résolu.");
                }
            }
        });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
