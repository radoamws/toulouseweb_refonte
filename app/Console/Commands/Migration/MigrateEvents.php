<?php

namespace App\Console\Commands\Migration;

use App\Models\Area;
use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 3 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : événements
 * agenda (t_agendas -> events) + catégories (t_agenda_cat). Corrige au
 * passage le bug legacy où id_area référençait t_agendas au lieu de
 * t_areas (voir audit DB §2). Nécessite `migrate:reference-data` avant.
 *
 * Statut dérivé de `t_agendas.status` (0/1, valeurs rares 5/6 observées) ET
 * de la date réelle : un événement passé est marqué "expired" même si le
 * flag legacy dit encore "actif" — la purge automatique (`events:archive-past`,
 * à implémenter en Phase 7) prendra ensuite le relais pour le nouveau contenu.
 */
class MigrateEvents extends Command
{
    protected $signature = 'migrate:events';

    protected $description = 'Migre les événements agenda (t_agendas) vers events, avec catégories';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();
        DB::connection('legacy')->disableQueryLog();

        $log = new MigrationLog('events');

        $areaMap = Area::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $categoryMap = EventCategory::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $eventCategories = DB::connection('legacy')->table('t_agenda_cat')->get()->groupBy('id_agenda');

        $today = now()->startOfDay();

        Event::withoutEvents(function () use ($log, $areaMap, $categoryMap, $eventCategories, $today) {
            DB::connection('legacy')->table('t_agendas')->orderBy('id')->chunk(500, function ($rows) use ($log, $areaMap, $categoryMap, $eventCategories, $today) {
                foreach ($rows as $row) {
                    $title = LegacyCleaner::text($row->title);
                    $startDate = LegacyCleaner::date($row->start_date);

                    if (! $title || ! $startDate) {
                        $log->skipped("Événement legacy #{$row->id} sans titre ou date de début valide — ignoré.");

                        continue;
                    }

                    $endDate = LegacyCleaner::date($row->end_date);
                    $areaId = $row->id_area ? ($areaMap[$row->id_area] ?? null) : null;
                    if ($row->id_area && $areaId === null) {
                        $log->warn("Événement legacy #{$row->id} ({$title}) : lieu legacy #{$row->id_area} introuvable.");
                    }

                    $status = match (true) {
                        in_array((int) $row->status, [5, 6], true) => 'cancelled',
                        \Illuminate\Support\Carbon::parse($endDate ?? $startDate)->lt($today) => 'expired',
                        (int) $row->status === 1 => 'published',
                        default => 'draft',
                    };

                    $existing = Event::withTrashed()->where('legacy_id', $row->id)->first();
                    $slug = $existing?->slug ?? LegacyCleaner::preserveSlug($row->slug, $title, 'events', $existing?->id);

                    $event = Event::updateOrCreate(
                        ['legacy_id' => $row->id],
                        [
                            'area_id' => $areaId,
                            'title' => $title,
                            'slug' => $slug,
                            'subtitle' => LegacyCleaner::text($row->sub_title),
                            'description' => LegacyCleaner::text($row->description),
                            'image' => LegacyCleaner::text($row->image),
                            'price' => LegacyCleaner::text($row->price),
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'booking_url' => LegacyCleaner::text($row->lien_resa) ?? LegacyCleaner::text($row->lien_detail),
                            'status' => $status,
                            'source' => 'manual',
                        ]
                    );

                    $existing ? $log->updated("#{$row->id} -> #{$event->id} ({$title})") : $log->created("#{$row->id} -> #{$event->id} ({$title})");

                    $categoryIds = ($eventCategories[$row->id] ?? collect())
                        ->map(fn ($r) => $categoryMap[$r->id_agenda_category] ?? null)->filter()->values();
                    $event->categories()->sync($categoryIds);
                }
            });
        });

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
