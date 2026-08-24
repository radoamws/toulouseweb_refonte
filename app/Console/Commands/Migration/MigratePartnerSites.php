<?php

namespace App\Console\Commands\Migration;

use App\Models\PartnerSite;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migre les sites partenaires (t_contact_sites, page contact). Table
 * manquante à l'audit initial (§10) — jamais migrée avant le travail
 * d'import des images (2026-08-24), qui en avait besoin comme préalable
 * (les logos n'ont de sens qu'attachés à une ligne `partner_sites` réelle).
 * Voir TECHNICAL_DOCUMENTATION.md §13.
 */
class MigratePartnerSites extends Command
{
    protected $signature = 'migrate:partner-sites';

    protected $description = 'Migre les sites partenaires (t_contact_sites) depuis toulouseweb_old';

    public function handle(): int
    {
        $log = new MigrationLog('partner-sites');

        $categories = DB::connection('legacy')->table('t_contact_sites_categ')->pluck('name', 'id');

        foreach (DB::connection('legacy')->table('t_contact_sites')->orderBy('id')->get() as $row) {
            $name = LegacyCleaner::text($row->nom);
            if (! $name) {
                $log->skipped("Site partenaire legacy #{$row->id} sans nom — ignoré.");

                continue;
            }

            $existing = PartnerSite::where('legacy_id', $row->id)->first();

            $site = PartnerSite::updateOrCreate(
                ['legacy_id' => $row->id],
                [
                    'name' => $name,
                    'url' => LegacyCleaner::text($row->url) ?? '',
                    'logo' => LegacyCleaner::text($row->image),
                    'category' => $categories[$row->categ_id] ?? null,
                    'order' => $row->id,
                ]
            );

            $existing ? $log->updated("#{$row->id} -> #{$site->id} ({$name})") : $log->created("#{$row->id} -> #{$site->id} ({$name})");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
