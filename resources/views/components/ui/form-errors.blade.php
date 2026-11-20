{{--
    Résumé d'erreurs générique (demande client, 17/11/2026 : "il faut
    toujours une message erreur ou validation partout où il y a un
    formulaire"). Bug réel trouvé en investiguant un rapport "rien ne se
    passe" sur /mon-compte/inscription et /mon-compte/connexion : la
    validation échouait bien côté serveur (`recaptcha_token` manquant/invalide
    — reCAPTCHA configuré même en local, voir App\Rules\Recaptcha), mais
    AUCUNE vue du site n'affichait d'erreur pour ce champ précis (ni pour le
    honeypot) — seuls les champs explicitement couverts par
    <x-ui.field-error name="..."> avaient un message, l'utilisateur voyait
    juste la page se recharger à l'identique. Affiché UNE SEULE FOIS par
    formulaire (pas de doublon avec les <x-ui.field-error> déjà posés sur
    chaque champ) — volontairement TOUS les messages ($errors->all()),
    recaptcha_token/website/url_verification n'ayant justement aucun
    <x-ui.field-error> dédié nulle part sur le site.
--}}
@if ($errors->any())
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
