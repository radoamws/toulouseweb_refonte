<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Mes publications" (demande client, 17/11/2026, point 3 : *"voir la liste
 * des publications confondus des utilisateurs pour qu'ils puissent faire des
 * modifs"*) — toutes entités confondues pour le compte éditeur connecté, voir
 * TECHNICAL_DOCUMENTATION.md.
 *
 * Protégé par le middleware `auth:editor` (routes/web.php) — jamais appelé
 * sans un éditeur authentifié.
 */
class EditorDashboardController extends Controller
{
    public function index(): View
    {
        $editor = Auth::guard('editor')->user();

        return view('editeur.mes-publications', [
            'editor' => $editor,
            'classifieds' => $editor->classifieds()->latest()->get(),
            'events' => $editor->events()->latest()->get(),
            'news' => $editor->news()->latest()->get(),
            'listings' => $editor->listings()->latest()->get(),
        ]);
    }
}
