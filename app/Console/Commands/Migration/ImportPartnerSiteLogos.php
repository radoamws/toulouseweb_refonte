<?php

namespace App\Console\Commands\Migration;

use App\Models\PartnerSite;
use App\Services\Migration\LegacyImageImporter;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Réimporte les logos des sites partenaires (t_contact_sites.image ->
 * partner_sites.logo) depuis `old/backEnd/public/contacsites/`. Nécessite
 * `migrate:partner-sites` au préalable (table jamais migrée avant ce
 * chantier, voir TECHNICAL_DOCUMENTATION.md §13).
 */
class ImportPartnerSiteLogos extends Command
{
    protected $signature = 'images:partner-sites';

    protected $description = 'Réimporte les logos des sites partenaires depuis old/backEnd/public/contacsites/';

    public function handle(): int
    {
        $log = new MigrationLog('images-partner-sites');
        $importer = new LegacyImageImporter([base_path('old/backEnd/public/contacsites')]);
        $log->warn("Index construit : {$importer->indexedFilesCount()} fichiers sous contacsites/.");

        foreach (PartnerSite::whereNotNull('logo')->get() as $site) {
            if (str_starts_with($site->logo, 'partners/')) {
                continue; // déjà résolu par un run précédent
            }

            $path = $importer->import($site->logo, 'partners');
            if ($path) {
                $site->update(['logo' => $path]);
                $log->updated("partner_sites#{$site->id} : {$path}");
            } else {
                $log->skipped("partner_sites#{$site->id} : fichier introuvable pour \"{$site->logo}\".");
            }
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
