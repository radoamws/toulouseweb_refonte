<?php

namespace App\Services\Scraping\Agenda\Concerns;

use App\Models\Area;
use App\Models\Event;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperDriver;
use Carbon\Carbon;

/**
 * Base commune aux salles utilisant la plateforme de billetterie "VEL"
 * (Ardei-Soft, `ardei-soft.com/{ville}/...`). Une INVESTIGATION LIVE
 * antérieure (voir TECHNICAL_DOCUMENTATION.md §13, driver
 * TheatreDeLaCiteDriver) avait conclu — à TORT — que cette plateforme était
 * "trop obfusquée" (JS minifié `VEL-javi.js`) pour être scrapée sans
 * rétro-ingénierie substantielle. Le VRAI code legacy fourni ensuite par le
 * client (`updateAgendaforEscale` et `updateAgendaforArdei`, voir
 * old/backEnd/app/Http/Controllers/AgendaController.php lignes ~2885 et
 * ~4735) prouve que c'est faux : l'appel est un simple
 * `file_get_contents()` sur `/{ville}/SenousritPGI?JAVOPP=GnAPIPlus&reqData={JSON}`
 * — aucun JS à exécuter, l'API répond directement du JSON.
 *
 * `reqData` contient `{"APIFunction":"ManifsSaison","crtManifs":{"dateD":"<horodatage>"},"mode":"synthese","gTarifs":N}`
 * — `dateD` semble être une date de référence (pas forcément "aujourd'hui"
 * strict côté legacy, qui utilise des valeurs codées en dur datées de 2022
 * /2024 dans le code trouvé) ; on envoie ici l'horodatage courant, l'API
 * paraissant retourner la saison en cours indépendamment de la valeur exacte.
 * `gTarifs` diffère selon la salle dans le code legacy (3 pour Escale/
 * Tournefeuille, 1 pour Ardei/Cornebarrieu) sans que sa signification soit
 * documentée côté legacy — probablement un identifiant de grille tarifaire
 * interne à VEL, reproduit tel quel.
 */
abstract class AbstractArdeiSoftDriver implements ScraperDriver
{
    use FetchesHttp;
    use ResolvesCategory;

    abstract protected function defaultTownSlug(): string;

    abstract protected function defaultAreaSlug(): string;

    /** gTarifs — voir docblock de classe. */
    abstract protected function defaultTarifsGroup(): int;

    /** @return int[] ids de App\Models\EventCategory à attacher */
    abstract protected function resolveCategoryIds(array $spectacle, array $payload): array;

    /**
     * Horaire affiché pour ce spectacle (06/09/2026, corrigé suite à l'audit
     * §18 de TECHNICAL_DOCUMENTATION.md) — chaque salle utilisant cette
     * plateforme a sa propre formule côté legacy (voir les 2 implémentations
     * concrètes, `EscaleDriver`/`ArdeiDriver`), pas de valeur par défaut
     * commune sensée : `null` ici tant qu'une sous-classe ne l'implémente pas.
     *
     * @return string[]|null
     */
    protected function computeSchedule(array $spectacle): ?array
    {
        return null;
    }

    public function run(ScraperSource $source): array
    {
        $config = $source->config ?? [];
        $town = $config['town_slug'] ?? $this->defaultTownSlug();
        $areaSlug = $config['area_slug'] ?? $this->defaultAreaSlug();
        $gTarifs = $config['tarifs_group'] ?? $this->defaultTarifsGroup();

        $area = Area::where('slug', $areaSlug)->first();
        if (! $area) {
            throw new \RuntimeException("Area (slug={$areaSlug}) introuvable — vérifier la config de la source.");
        }

        $reqData = json_encode([
            'APIFunction' => 'ManifsSaison',
            'crtManifs' => ['dateD' => now()->format('Y-m-d\TH:i')],
            'mode' => 'synthese',
            'gTarifs' => $gTarifs,
        ]);

        // rawurlencode() est indispensable : les accolades/guillemets bruts du
        // JSON ne sont pas des caractères d'URI valides (RFC 3986) — le client
        // HTTP (Guzzle) échoue silencieusement à parser l'URL sinon, alors que
        // curl en ligne de commande les tolère (constaté en direct le
        // 25/08/2026, contrairement au code legacy qui interpole le JSON brut).
        $url = "https://www.ardei-soft.com/{$town}/SenousritPGI?JAVOPP=GnAPIPlus&reqData=".rawurlencode($reqData);

        $payload = $this->fetchJson($url);
        if ($payload === null) {
            throw new \RuntimeException("Échec de récupération de l'API Ardei-Soft ({$url}).");
        }

        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        $spectacles = $payload['spectacles'] ?? [];

        foreach ($spectacles as $spectacle) {
            if (empty($spectacle['fmm1'])) {
                // Le legacy ignore aussi les entrées sans visuel — souvent des
                // lignes techniques/placeholder côté VEL, pas de vrais spectacles.
                continue;
            }

            $stats['found']++;

            $externalRef = $spectacle['s'] ?? null;
            $title = $spectacle['lLbl'] ?? $spectacle['s'] ?? null;

            if (! $externalRef || ! $title) {
                $stats['skipped']++;

                continue;
            }

            $start = $this->extractDate($spectacle['dateD'] ?? null);
            $end = $this->extractDate($spectacle['dateF'] ?? null) ?? $start;

            if (! $start) {
                $stats['skipped']++;

                continue;
            }

            $price = isset($spectacle['prixMin'], $spectacle['prixMax'])
                ? "de {$spectacle['prixMin']} € à {$spectacle['prixMax']} €"
                : null;

            $existing = Event::where('external_ref', $externalRef)->exists();

            $ev = Event::updateOrCreate(
                ['external_ref' => $externalRef],
                array_filter([
                    'area_id' => $area->id,
                    'title' => $title,
                    'description' => $spectacle['txt'] ?? null,
                    'price' => $price,
                    'schedule' => $this->computeSchedule($spectacle),
                    'image' => "https://www.ardei-soft.com/{$town}/img/{$spectacle['fmm1']}",
                    'start_date' => $start,
                    'end_date' => $end,
                    'booking_url' => "https://www.ardei-soft.com/{$town}/spectacle.html?spectacle={$externalRef}",
                    'status' => 'published',
                    'source' => 'scraped',
                ], fn ($value) => $value !== null)
            );

            $categoryIds = $this->resolveCategoryIds($spectacle, $payload);
            if ($categoryIds) {
                $ev->categories()->syncWithoutDetaching($categoryIds);
            }

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    /** @param array{0?:int,1?:int,2?:int,3?:int,4?:int}|null $parts [année, mois, jour, heure, minute] */
    protected function extractDate(?array $parts): ?Carbon
    {
        if (! $parts || count($parts) < 3) {
            return null;
        }

        try {
            return Carbon::create(
                (int) $parts[0],
                (int) $parts[1],
                (int) $parts[2],
                (int) ($parts[3] ?? 0),
                (int) ($parts[4] ?? 0)
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
