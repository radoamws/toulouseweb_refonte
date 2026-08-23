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
                        'link_url' => LegacyCleaner::text($row->urlBillboard),
                        'client_name' => LegacyCleaner::text($row->client),
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
