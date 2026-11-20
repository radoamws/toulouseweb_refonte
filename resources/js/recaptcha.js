/**
 * reCAPTCHA v3 (invisible) — protège les formulaires publics contre le spam
 * en complément du honeypot déjà en place (voir App\Rules\Recaptcha).
 * Délégation d'événement sur tout <form data-recaptcha-action="..."> plutôt
 * qu'un script par formulaire (même approche que track-click.js).
 * N'agit pas si la clé publique n'est pas configurée (dev local sans clé) :
 * les formulaires se soumettent alors normalement, sans jeton.
 */
function siteKey() {
    return document.querySelector('meta[name="recaptcha-site-key"]')?.getAttribute('content') || null;
}

function loadScript(key) {
    if (window.grecaptcha) {
        return new Promise((resolve) => window.grecaptcha.ready(resolve));
    }
    if (window.__recaptchaLoading) {
        return window.__recaptchaLoading;
    }

    window.__recaptchaLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `https://www.google.com/recaptcha/api.js?render=${key}`;
        script.onload = () => window.grecaptcha.ready(resolve);
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return window.__recaptchaLoading;
}

function setToken(form, token) {
    let input = form.querySelector('input[name="recaptcha_token"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'recaptcha_token';
        form.appendChild(input);
    }
    input.value = token;
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    const action = form?.dataset?.recaptchaAction;
    if (!action || form.dataset.recaptchaVerified === 'true') {
        return;
    }

    const key = siteKey();
    if (!key) {
        return;
    }

    event.preventDefault();

    // Filet de sécurité (17/11/2026, bug réel trouvé en investiguant un
    // rapport "je valide le formulaire, rien ne se passe" sur /mon-compte) :
    // si `grecaptcha.execute()` ne résout NI ne rejette dans un délai
    // raisonnable (script Google qui charge mais ne répond jamais,
    // comportement observé en local), la Promise ci-dessous restait
    // indéfiniment en attente — `.catch()` seul ne couvre que l'ÉCHEC
    // explicite, pas l'absence de réponse. `Promise.race` avec un délai
    // garantit que le formulaire part toujours, avec ou sans jeton (le
    // serveur reste fail-open sans jeton si reCAPTCHA est injoignable, voir
    // App\Rules\Recaptcha) — jamais bloquer un vrai visiteur indéfiniment.
    const timeout = new Promise((resolve) => setTimeout(() => resolve(null), 4000));

    Promise.race([
        loadScript(key).then(() => window.grecaptcha.execute(key, { action })),
        timeout,
    ])
        .then((token) => {
            if (token) {
                setToken(form, token);
            }
            form.dataset.recaptchaVerified = 'true';
            form.submit();
        })
        .catch(() => {
            // Google injoignable : on laisse partir le formulaire quand même
            // (voir App\Rules\Recaptcha, fail-open côté serveur aussi) —
            // jamais bloquer un vrai visiteur pour une panne tierce.
            form.dataset.recaptchaVerified = 'true';
            form.submit();
        });
});
