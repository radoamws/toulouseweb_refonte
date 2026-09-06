{{--
    Colonnes de tableau redimensionnables à la souris, sur TOUS les tableaux
    admin (demande client : "réduire un peu la colonne titre. Ou mieux,
    permettre d'étirer/réduire la largeur des colonnes").

    Filament (v3.3, vérifié dans vendor/filament/tables) n'a pas de fonction
    native de redimensionnement de colonnes — implémenté ici en JS
    vanilla (pas de dépendance), injecté globalement via un render hook
    (panels::body.end, voir AdminPanelProvider) plutôt que dans chaque
    Resource, pour couvrir toutes les listes admin d'un coup.

    Persistance par colonne dans localStorage (clé = chemin de la page +
    index de la colonne dans l'en-tête) : chaque admin retrouve ses largeurs
    au rechargement, MAIS c'est un réglage local au navigateur (pas partagé
    entre admins/appareils) — cohérent avec le reste des préférences
    d'affichage Filament (colonnes masquées via ->toggleable(), etc., qui
    fonctionnent pareil).

    Les tableaux Filament sont rendus par Livewire et se re-rendent
    (remplacement du DOM) au tri/filtre/pagination sans rechargement de
    page — d'où le MutationObserver qui ré-applique les largeurs
    sauvegardées et ré-attache les poignées de redimensionnement à chaque
    mutation du DOM, au lieu d'un simple listener au chargement de la page.
--}}
<style>
    .tw-col-resize-handle {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: 8px;
        cursor: col-resize;
        z-index: 5;
        touch-action: none;
    }
    .tw-col-resize-handle:hover,
    .tw-col-resize-handle.is-dragging {
        background: rgba(120, 120, 120, .35);
    }
</style>
<script>
    (function () {
        function debounce(fn, wait) {
            var t;
            return function () {
                var args = arguments, ctx = this;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(ctx, args); }, wait);
            };
        }

        function applyColumnWidth(table, th, index, px) {
            th.style.width = px + 'px';
            th.style.maxWidth = px + 'px';
            th.style.overflow = 'hidden';
            th.style.textOverflow = 'ellipsis';

            table.querySelectorAll(':scope > tbody > tr').forEach(function (tr) {
                var td = tr.children[index];
                if (! td) return;
                td.style.width = px + 'px';
                td.style.maxWidth = px + 'px';
                td.style.overflow = 'hidden';
                td.style.textOverflow = 'ellipsis';
            });
        }

        function attachDrag(table, th, handle, key) {
            var startX, startWidth, index;

            function onMouseMove(e) {
                var delta = e.clientX - startX;
                var width = Math.max(60, Math.round(startWidth + delta));
                applyColumnWidth(table, th, index, width);
            }

            function onMouseUp() {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                document.body.style.userSelect = '';
                handle.classList.remove('is-dragging');
                localStorage.setItem(key, String(Math.round(th.getBoundingClientRect().width)));
            }

            handle.addEventListener('mousedown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                index = Array.prototype.indexOf.call(th.parentElement.children, th);
                startX = e.clientX;
                startWidth = th.getBoundingClientRect().width;
                table.style.tableLayout = 'fixed';
                document.body.style.userSelect = 'none';
                handle.classList.add('is-dragging');
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        }

        function applyResizableColumns() {
            document.querySelectorAll('table.fi-ta-table').forEach(function (table) {
                var headerRow = table.querySelector(':scope > thead > tr');
                if (! headerRow) return;

                Array.prototype.forEach.call(headerRow.children, function (th, index) {
                    var key = 'tw-admin-col-width:' + location.pathname + ':' + index;
                    var saved = localStorage.getItem(key);

                    if (saved && th.dataset.twWidth !== saved) {
                        th.dataset.twWidth = saved;
                        applyColumnWidth(table, th, index, parseInt(saved, 10));
                        table.style.tableLayout = 'fixed';
                    }

                    if (! th.querySelector(':scope > .tw-col-resize-handle')) {
                        th.style.position = 'relative';
                        var handle = document.createElement('span');
                        handle.className = 'tw-col-resize-handle';
                        th.appendChild(handle);
                        attachDrag(table, th, handle, key);
                    }
                });
            });
        }

        var run = debounce(applyResizableColumns, 50);
        document.addEventListener('DOMContentLoaded', run);
        document.addEventListener('livewire:navigated', run);
        new MutationObserver(run).observe(document.documentElement, { childList: true, subtree: true });
        run();
    })();
</script>
