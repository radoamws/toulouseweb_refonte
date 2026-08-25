<?php

namespace App\Services\Scraping\Agenda\Concerns;

use Carbon\Carbon;

/**
 * Parseur de dates françaises texte-libre — reproduit (de façon simplifiée
 * mais fonctionnellement équivalente) `getDateDebFinAgenda()` du legacy (voir
 * old/backEnd/app/Http/Controllers/AgendaController.php ligne ~3483),
 * utilisé par plusieurs drivers (Garonne, Odyssud, Interprète, Le Vent des
 * Signes) pour parser des dates du type "12 mars" / "12 mar 2026" sans
 * dépendre d'un fuseau/locale serveur.
 *
 * Simplification connue par rapport au legacy : l'original gère aussi des
 * abréviations tronquées incohérentes ("dc", "sp", "fv"...) issues de bugs de
 * troncature de texte côté sites sources historiques — non repris ici faute
 * de cas réel observé sur les sites actuels ; à enrichir si un site scrapé
 * produit une abréviation non reconnue (voir TECHNICAL_DOCUMENTATION.md §13,
 * "Ce qui reste").
 */
trait ParsesFrenchDates
{
    private const MONTHS_ABBR = [
        'janvier' => 1, 'janv' => 1, 'jan' => 1,
        'fevrier' => 2, 'fevr' => 2, 'fev' => 2,
        'mars' => 3, 'mar' => 3,
        'avril' => 4, 'avr' => 4,
        'mai' => 5,
        'juin' => 6, 'jun' => 6,
        'juillet' => 7, 'juil' => 7, 'jul' => 7,
        'aout' => 8, 'aou' => 8, 'au' => 8,
        'septembre' => 9, 'sept' => 9, 'sep' => 9,
        'octobre' => 10, 'oct' => 10,
        'novembre' => 11, 'nov' => 11,
        'decembre' => 12, 'dec' => 12,
    ];

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function parseFrenchDateRange(string $start, ?string $end = null): array
    {
        $startParsed = $this->parseSingleFrenchDate($start);
        $endParsed = $end ? $this->parseSingleFrenchDate($end) : $startParsed;

        return [$startParsed, $endParsed ?? $startParsed];
    }

    protected function parseSingleFrenchDate(string $text): ?Carbon
    {
        $text = trim(preg_replace('/[[:^print:]]/u', '', $text) ?? $text);
        $text = str_replace(['à partir du', 'Du', 'du'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (! preg_match('/(\d{1,2})\s+([a-zA-Zéûôîâ.]+)\.?\s*(\d{4})?/u', $text, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $monthKey = $this->normalizeMonthToken($matches[2]);
        $month = self::MONTHS_ABBR[$monthKey] ?? null;

        if (! $month) {
            return null;
        }

        $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : (int) now()->format('Y');

        if (! isset($matches[3]) && $month < (int) now()->format('n')) {
            // Pas d'année explicite et mois déjà passé cette année : la saison
            // vise très probablement l'année suivante (même heuristique que
            // le legacy — `if ($ddm < $acualMonth) $ddy = $ddy + 1;`).
            $year++;
        }

        try {
            return Carbon::create($year, $month, $day);
        } catch (\Throwable) {
            return null;
        }
    }

    private const ACCENT_MAP = [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'û' => 'u', 'ù' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];

    protected function normalizeMonthToken(string $token): string
    {
        $token = mb_strtolower(trim($token, ". \t\n\r"));

        // iconv('...//TRANSLIT...') est locale-dépendant et peut produire des
        // apostrophes parasites ("é" → "'e") au lieu d'une simple suppression
        // d'accent (constaté en direct le 25/08/2026 sur "Déc"/"Fév") — on
        // fait donc une translittération manuelle, stable quel que soit
        // l'environnement serveur.
        return strtr($token, self::ACCENT_MAP);
    }
}
