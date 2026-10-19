<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déclencheur HTTP SÉPARÉ de `transport:import-tisseo-schedule` (brief
 * roadmap "contenu", 16/10/2026 — itinéraire avec correspondance),
 * VOLONTAIREMENT distinct de App\Http\Controllers\WebCronController : le
 * benchmark réel du 16/10/2026 a mesuré ~236 000 lignes stop_times/semaine
 * et ~30s rien que pour leur insertion en lots (poste de dev) — empiler ça
 * dans la même requête HTTP que `RunWebCron` (déjà : sitemap + scraping
 * cinéma + ~15 sources agenda + dédoublonnage, en un seul appel synchrone)
 * ferait courir un risque réel de dépasser la limite d'exécution PHP-FPM du
 * serveur web mutualisé.
 *
 * Même principe de sécurité que WebCronController (jeton long/aléatoire
 * dans l'URL, comparaison en temps constant, 404 — pas 403 — sur jeton
 * invalide), secret DIFFÉRENT (`WEBCRON_TRANSPORT_SECRET`). Nécessite un 2e
 * WebCron à paramétrer dans le manager Infomaniak, à un horaire différent
 * du WebCron principal — voir README.md.
 */
class WebCronTransportController extends Controller
{
    public function __invoke(string $token): Response
    {
        $expected = (string) config('services.webcron_transport.secret');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        Artisan::call('transport:import-tisseo-schedule');

        return response(Artisan::output(), 200)->header('Content-Type', 'text/plain');
    }
}
