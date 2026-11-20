<?php

namespace App\Http\Controllers;

use App\Models\Editor;
use App\Models\MissedRedirect;
use App\Models\Redirect;
use App\Services\Stats\PageViewService;
use App\Support\SubmitterNotifier;
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

    /**
     * Partagée par les 4 contrôleurs de dépôt public (Classified/Event/News/
     * Listing) — demande client, 17/11/2026 : *"après qu'un nouveau client a
     * publié une entité... changer le mail [...] avec le lien pour pouvoir
     * créer son compte pour qu'il administre toutes ses publications"*.
     * Remplace l'ancien email pointant vers le lien par jeton propre à LA
     * fiche qui vient d'être créée (§74-75, toujours fonctionnel pour les
     * liens déjà envoyés) : un compte pas encore inscrit obtient le lien
     * d'inscription, un compte déjà inscrit (un ancien dépôt, par exemple)
     * obtient simplement un lien de connexion — jamais de double-compte.
     * N'est appelée que pour un dépôt ANONYME (voir chaque `store()`) : un
     * déposant déjà connecté a déjà accès à son tableau de bord, un email
     * de plus n'apporterait rien.
     */
    protected function notifySubmitterToManageTheirAccount(Editor $editor, string $entityTitle, string $heading): void
    {
        $url = $editor->hasRegistered()
            ? route('editeur.connexion')
            : route('editeur.inscription', $editor->invitation_token);

        SubmitterNotifier::send(
            $editor->email,
            $heading,
            ['Titre' => $entityTitle],
            $url,
            $editor->hasRegistered() ? 'Se connecter à mon espace' : 'Créer mon espace',
        );
    }
}
