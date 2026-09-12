<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En-têtes de sécurité sur toutes les réponses (brief §18), voir
        // App\Http\Middleware\SecurityHeaders.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // ⚠️ Bug réel trouvé et corrigé (12/09/2026, demande client : "les
        // stats ne semblent pas fonctionner, j'ai navigué sur plusieurs
        // pages et aucun clic ne s'enregistre") — resources/js/track-click.js
        // envoie via `navigator.sendBeacon()` en priorité (utilisé par la
        // quasi-totalité des navigateurs modernes), une API qui NE PEUT PAS
        // envoyer d'en-tête personnalisé (donc jamais de X-CSRF-TOKEN) — le
        // fallback `fetch()` avec le jeton n'est utilisé QUE si sendBeacon
        // est indisponible, un cas devenu rarissime. Résultat vérifié en
        // direct sur la vraie production : un POST /track-click sans jeton
        // répond 419 — et `sendBeacon` n'expose jamais l'échec au JS
        // (fire-and-forget), donc CHAQUE clic échouait silencieusement,
        // sans la moindre erreur visible où que ce soit. Exempté du CSRF
        // comme n'importe quel endpoint de type "beacon" (webhook) : ne
        // réalise aucune action privilégiée liée à l'identité du visiteur,
        // se contente d'incrémenter un compteur de clics anonyme (IP/session
        // déjà hachées, voir ClickTrackingService::record()) — throttle:60,1
        // (déjà en place sur la route) reste la protection contre l'abus.
        $middleware->validateCsrfTokens(except: [
            'track-click',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
