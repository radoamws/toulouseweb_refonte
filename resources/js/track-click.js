/**
 * Tracking de clics générique — voir App\Http\Controllers\ClickTrackingController
 * et TECHNICAL_DOCUMENTATION.md §9/§13. N'importe quel élément cliquable
 * (bannière, carte catégorie, encadré, fiche ciné/film...) peut être suivi
 * en ajoutant simplement :
 *   <a href="..." data-track="listing:123:homepage_card">...</a>
 * Format : "entity_type:entity_id:context" (context optionnel).
 * Envoi non bloquant (sendBeacon si disponible) pour ne jamais retarder la
 * navigation réelle du lien.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function track(entityType, entityId, context) {
    const payload = JSON.stringify({
        entity_type: entityType,
        entity_id: Number(entityId),
        context: context || null,
        url: window.location.href,
    });

    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon('/track-click', blob);
        return;
    }

    fetch('/track-click', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        body: payload,
        keepalive: true,
    }).catch(() => {});
}

document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-track]');
    if (!el) return;

    const [entityType, entityId, context] = el.dataset.track.split(':');
    if (entityType && entityId) {
        track(entityType, entityId, context);
    }
});
