<?php

namespace App\Http\Controllers;

use App\Models\Editor;
use App\Rules\Recaptcha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Authentification des comptes "éditeur" (demande client, 17/11/2026) — voir
 * TECHNICAL_DOCUMENTATION.md et le docblock de App\Models\Editor pour la
 * séparation volontaire d'avec l'authentification admin (`web`/Filament).
 *
 * `register()` (inscription par jeton d'invitation) et `signup()`
 * (inscription libre, ajoutée le 17/11/2026, demande client — voir son
 * docblock ci-dessous) coexistent : le jeton reste le seul chemin pour les
 * comptes créés par la migration historique (§76), `signup()` est le chemin
 * public pour un nouveau déposant qui s'inscrit de lui-même AVANT même
 * d'avoir déposé quoi que ce soit. `login()`/mot de passe oublié restent
 * protégés par honeypot + throttle + reCAPTCHA (App\Rules\Recaptcha, même
 * convention que les formulaires de dépôt publics) en défense en profondeur.
 */
class EditorAuthController extends Controller
{
    /**
     * Point d'entrée du lien envoyé par email. Un compte déjà inscrit
     * (mot de passe déjà choisi) est redirigé vers la connexion plutôt que
     * de réafficher un formulaire d'inscription — demande client explicite
     * ("si l'inscription est déjà soumise, le lien dans le mail va juste
     * pointer sur une petite page de login").
     */
    public function invitation(string $token): View|RedirectResponse
    {
        $editor = Editor::where('invitation_token', $token)->firstOrFail();

        if ($editor->hasRegistered()) {
            return redirect()->route('editeur.connexion');
        }

        return view('editeur.inscription', ['editor' => $editor, 'token' => $token]);
    }

    public function register(Request $request, string $token): RedirectResponse
    {
        $editor = Editor::where('invitation_token', $token)->firstOrFail();

        if ($editor->hasRegistered()) {
            return redirect()->route('editeur.connexion');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'website' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('editeur_inscription'),
        ]);

        $editor->update([
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'invitation_token' => null,
        ]);

        Auth::guard('editor')->login($editor);
        $request->session()->regenerate();

        return redirect()->route('editeur.mes-publications');
    }

    /**
     * Inscription libre (demande client, 17/11/2026 : "ajoute aussi
     * l'option de s'inscrire en front") — par opposition à
     * `invitation()`/`register()` ci-dessus (jeton envoyé par email),
     * accessible depuis le menu "Se connecter" du header et la page de
     * connexion, SANS jeton. Reconnecte la friction anti-robot perdue en
     * ouvrant ce chemin : honeypot + throttle + reCAPTCHA, même protection
     * que les 4 formulaires de dépôt public.
     */
    public function showSignup(): View
    {
        return view('editeur.inscription-libre');
    }

    public function signup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'website' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('editeur_inscription'),
        ]);

        $existing = Editor::where('email', $validated['email'])->first();

        if ($existing && $existing->hasRegistered()) {
            return back()->withErrors(['email' => 'Un compte existe déjà avec cet email — connectez-vous plutôt.'])->onlyInput('name', 'email');
        }

        if ($existing) {
            // Compte créé par la migration historique (§76), jamais encore
            // inscrit (invitation en attente) : on le complète plutôt que
            // d'échouer sur l'unicité de l'email — garde le rattachement à
            // ses publications déjà existantes (`editor_id`), voir
            // MigrateHistoricalSubmittersToEditors.
            $editor = tap($existing)->update([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'invitation_token' => null,
            ]);
        } else {
            $editor = Editor::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
        }

        Auth::guard('editor')->login($editor);
        $request->session()->regenerate();

        return redirect()->route('editeur.mes-publications');
    }

    public function showLogin(): View
    {
        return view('editeur.connexion');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'website' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('editeur_connexion'),
        ]);

        if (! Auth::guard('editor')->attempt(['email' => $validated['email'], 'password' => $validated['password']], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Identifiants incorrects.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('editeur.mes-publications'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('editor')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    public function showForgotPassword(): View
    {
        return view('editeur.mot-de-passe-oublie');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'website' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('editeur_mot_de_passe'),
        ]);

        // Message identique que l'email existe ou non (évite de révéler
        // quels emails ont un compte) — Password::sendResetLink() ne
        // renseigne de toute façon `invitation_token`/mot de passe que pour
        // un compte déjà inscrit (voir Editor::sendPasswordResetNotification()).
        Password::broker('editors')->sendResetLink($validated);

        return back()->with('status', "Si un compte existe avec cet email, un lien de réinitialisation vient d'être envoyé.");
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('editeur.reinitialiser-mot-de-passe', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'website' => ['size:0'],
        ]);

        $status = Password::broker('editors')->reset($validated, function (Editor $editor, string $password) {
            $editor->update(['password' => Hash::make($password)]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('editeur.connexion')->with('status', 'Votre mot de passe a été réinitialisé — vous pouvez vous connecter.');
    }
}
