<?php

namespace App\Console\Commands\Migration;

use App\Models\Category;
use App\Models\ClickEvent;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\Page;
use App\Models\Slider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 10 (dernière) du plan de migration (TECHNICAL_DOCUMENTATION.md §10) :
 * historique de clics (t_stat_counter, ~2,78M lignes) -> click_events.
 * Volumineuse : traitée par lots avec insertion brute (pas d'Eloquent par
 * ligne). Ne migre que les types réellement utilisés en legacy
 * (rubrique/encadré/news/accueil/agenda/sliders) — annonces/cinéma/forum/
 * contact n'ont jamais eu une seule ligne en legacy (voir audit §2.3) donc
 * rien à migrer pour ces types.
 *
 * Certains id_entite legacy sont corrompus/orphelins (ex: id_entite jusqu'à
 * 20+ millions pour le type "agenda", très au-delà de tout id réel) —
 * silencieusement ignorés (comptés, pas un par un) plutôt que d'échouer.
 */
class MigrateClickStats extends Command
{
    protected $signature = 'migrate:click-stats {--truncate : Vide click_events avant import (nécessaire pour rejouer la commande)}';

    protected $description = 'Migre l\'historique de clics (t_stat_counter) vers click_events, par lots';

    protected const CHUNK_SIZE = 5000;

    public function handle(): int
    {
        // Indispensable sur un aussi gros volume : le query log de Laravel
        // (actif par défaut en debug local) accumulerait ~2,78M entrées en
        // mémoire sinon.
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        if (ClickEvent::count() > 0) {
            if (! $this->option('truncate')) {
                $this->error('click_events contient déjà des données. Relancer avec --truncate pour repartir de zéro.');

                return self::FAILURE;
            }
            ClickEvent::truncate();
        }

        $categoryMap = Category::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $listingMap = Listing::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $newsMap = News::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $eventMap = Event::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $sliderMap = Slider::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $homePageId = Page::where('key', 'home')->value('id');

        // id_stat_entite legacy -> [entity_type cible, map de résolution]
        $typeResolvers = [
            1 => ['categories', $categoryMap],
            2 => ['listings', $listingMap],
            3 => ['news', $newsMap],
            5 => ['events', $eventMap],
            10 => ['sliders', $sliderMap],
        ];

        $total = DB::connection('legacy')->table('t_stat_counter')->count();
        $bar = $this->output->createProgressBar($total);
        $inserted = 0;
        $skipped = 0;

        DB::connection('legacy')->table('t_stat_counter')->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($rows) use ($typeResolvers, $homePageId, $bar, &$inserted, &$skipped) {
                $batch = [];
                foreach ($rows as $row) {
                    $type = (int) $row->id_stat_entite;

                    if ($type === 4) {
                        // "accueil" : entité unique factice côté legacy -> notre page 'home'
                        if ($homePageId) {
                            $batch[] = [
                                'entity_type' => 'pages',
                                'entity_id' => $homePageId,
                                'context' => 'legacy_homepage',
                                'created_at' => $row->date,
                            ];
                        } else {
                            $skipped++;
                        }

                        continue;
                    }

                    [$entityType, $map] = $typeResolvers[$type] ?? [null, null];
                    if (! $entityType || ! isset($map[$row->id_entite])) {
                        $skipped++;

                        continue;
                    }

                    $batch[] = [
                        'entity_type' => $entityType,
                        'entity_id' => $map[$row->id_entite],
                        'context' => $row->id_sous_categ ? "legacy_sous_categ_{$row->id_sous_categ}" : null,
                        'created_at' => $row->date,
                    ];
                }

                foreach (array_chunk($batch, 1000) as $insertChunk) {
                    DB::table('click_events')->insert($insertChunk);
                    $inserted += count($insertChunk);
                }

                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info("Clics migrés : {$inserted}, ignorés (type/entité non résolus) : {$skipped}");

        return self::SUCCESS;
    }
}
