<?php

namespace App\Http\Controllers;

use App\Models\MissedRedirect;
use App\Models\Redirect;
use App\Services\Stats\PageViewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class Controller
{
    /**
     * Enregistre une vue de page (demande client, 15/09/2026, voir
     * App\Services\Stats\PageViewService et TECHNICAL_DOCUMENTATION.md §44)
     * — à appeler juste avant chaque `return view(...)` d'une page
     * publique. `rescue()` (log + avale l'exception, ne la relance jamais) :
     * un souci d'enregistrement des stats ne doit JAMAIS empêcher
     * l'affichage réel de la page au visiteur.
     */
    protected function recordPageView(Request $request, ?string $entityType = null, ?int $entityId = null): void
    {
        rescue(fn () => app(PageViewService::class)->record($request, $request->path(), $entityType, $entityId));
    }

    /**
     * Consulte la table `redirects` avant d'abandonner en 404 (brief §15).
     *
     * IMPORTANT : `Route::fallback()` (voir routes/web.php) ne suffit PAS
     * pour la plupart de nos redirections, car l'essentiel des entrées
     * migrées partagent la MÊME forme d'URL que les routes actuelles
     * (ex: /annuaire/fiche/{ancien-slug} -> /annuaire/fiche/{nouveau-slug}) :
     * le routeur "matche" déjà un pattern existant et ne tombe donc jamais
     * dans le fallback, qui ne voit que les URLs de forme complètement
     * différente. Chaque contrôleur doit donc appeler explicitement cette
     * méthode avant son 404 final.
     */
    protected function redirectOrAbort(string $path): RedirectResponse
    {
        $path = '/'.ltrim($path, '/');

        $redirect = Redirect::where('from_path', $path)->where('is_active', true)->first();

        if (! $redirect) {
            MissedRedirect::record($path);

            throw new NotFoundHttpException;
        }

        $redirect->increment('hits_count');

        return redirect($redirect->to_path, $redirect->status_code);
    }
}
