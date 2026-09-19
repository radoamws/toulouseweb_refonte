<?php

namespace App\Console\Commands\Migration;

use App\Models\Area;
use App\Models\Event;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;

/**
 * Fusionne 2 fiches `Area` en doublon (même lieu physique, 2 lignes
 * distinctes issues de l'import legacy) — demande client, 19/09/2026 : "Il y
 * a 2 Areas 'Casino barrière' dans l'admin: supprime l'un et bascule les
 * agendas correspondants a celui supprimé vers celui qui reste."
 *
 * Réassigne tous les `Event::area_id` (y compris soft-deleted, pour rester
 * cohérent) de `$remove` vers `$keep`, puis supprime `$remove`.
 *
 * Volontairement scopé à UNE paire à la fois (slugs explicites en argument),
 * pas un dédoublonnage générique de la table `areas` : un audit complet a
 * trouvé des CENTAINES de noms identiques dans les ~3845 lignes importées de
 * l'ancien site (ex. "Salle des fêtes" ×4, "Place des Tiercerettes" ×4...) —
 * la plupart désignent très probablement des lieux DIFFÉRENTS dans des villes
 * différentes partageant un nom générique, pas de vrais doublons : les fusionner
 * à l'aveugle casserait des lieux légitimes. Seule "Casino Théâtre Barrière"
 * a été confirmée comme un vrai doublon exact (même salle, même scraper) au
 * 19/09/2026 — voir TECHNICAL_DOCUMENTATION.md.
 */
class MergeDuplicateArea extends Command
{
    protected $signature = 'content:merge-duplicate-area {keep : Slug de l\'Area à conserver} {remove : Slug de l\'Area à supprimer}';

    protected $description = "Réassigne les événements d'une Area en doublon vers l'Area à conserver, puis supprime le doublon";

    public function handle(): int
    {
        $log = new MigrationLog('merge-duplicate-area');

        $keep = Area::where('slug', $this->argument('keep'))->first();
        $remove = Area::where('slug', $this->argument('remove'))->first();

        if (! $keep || ! $remove) {
            $this->error('Area(s) introuvable(s) — vérifier les slugs fournis.');

            return self::FAILURE;
        }

        if ($keep->id === $remove->id) {
            $this->error('Les deux slugs pointent vers la même Area.');

            return self::FAILURE;
        }

        $moved = Event::withTrashed()->where('area_id', $remove->id)->count();

        Event::withTrashed()->where('area_id', $remove->id)->update(['area_id' => $keep->id]);
        $log->created("{$moved} événement(s) rebasculé(s) de \"{$remove->name}\" (#{$remove->id}) vers \"{$keep->name}\" (#{$keep->id}).");

        $remove->delete();
        $log->created("Area \"{$remove->name}\" (#{$remove->id}, slug={$remove->slug}) supprimée.");

        $this->info("{$moved} événement(s) rebasculé(s) vers \"{$keep->name}\" (#{$keep->id}). Area \"{$remove->name}\" (#{$remove->id}) supprimée.");
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
