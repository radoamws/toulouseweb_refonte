<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déclencheur HTTP de `webcron:run` (voir docblock de la commande et
 * TECHNICAL_DOCUMENTATION.md §27) — nécessaire car l'hébergement
 * Infomaniak mutualisé n'offre qu'un "Planificateur de tâches" qui appelle
 * une URL, pas un vrai crontab serveur.
 *
 * Protégé par un jeton secret dans l'URL elle-même (pas de notion de
 * session/CSRF pertinente ici, appelé par un service externe plutôt que
 * par un navigateur) — comparaison en temps constant (`hash_equals`) pour
 * ne pas laisser fuiter d'information via le temps de réponse. Retourne
 * 404 (pas 403) sur un jeton invalide pour ne rien révéler sur l'existence
 * de la route à un client qui devinerait au hasard.
 */
class WebCronController extends Controller
{
    public function __invoke(string $token): Response
    {
        $expected = (string) config('services.webcron.secret');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        Artisan::call('webcron:run');

        return response(Artisan::output(), 200)->header('Content-Type', 'text/plain');
    }
}
