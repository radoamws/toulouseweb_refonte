<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de base (brief §18) — l'ancien site n'en posait
 * aucun (voir audit backend §4/§6). Volontairement minimal et sans risque
 * de casser l'affichage (pas de CSP stricte qui bloquerait les données
 * `data:` du favicon ou les scripts Alpine/Vite inline nécessaires).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // Défense en profondeur pour /admin (08/09/2026, audit SEO final,
        // TECHNICAL_DOCUMENTATION.md §24) : robots.txt bloque déjà /admin,
        // mais un `Disallow` n'empêche pas Google d'indexer une URL nue (sans
        // contenu) si un lien externe y pointe un jour — un en-tête
        // `X-Robots-Tag: noindex` l'empêche réellement, contrairement au
        // simple blocage d'exploration.
        if ($request->is('admin', 'admin/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // HSTS uniquement en production : un en-tête envoyé par erreur en
        // dev/staging HTTP est inoffensif (ignoré par les navigateurs sur
        // une réponse non-HTTPS) mais autant rester explicite — voir
        // checklist de déploiement, TECHNICAL_DOCUMENTATION.md §14.
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
