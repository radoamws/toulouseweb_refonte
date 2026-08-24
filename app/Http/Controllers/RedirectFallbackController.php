<?php

namespace App\Http\Controllers;

use App\Models\MissedRedirect;
use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dernier recours pour toute URL qui ne correspond à aucune route : sert les
 * redirections 301 administrables (brief §15) avant d'abandonner sur un
 * vrai 404. Enregistré via `Route::fallback()` (routes/web.php), donc
 * seulement consulté après échec de toutes les routes explicites.
 */
class RedirectFallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        if (! $request->isMethod('GET')) {
            abort(404);
        }

        $path = '/'.ltrim($request->path(), '/');

        $redirect = Redirect::where('from_path', $path)->where('is_active', true)->first();

        if (! $redirect) {
            MissedRedirect::record($path);

            abort(404);
        }

        $redirect->increment('hits_count');

        return redirect($redirect->to_path, $redirect->status_code);
    }
}
