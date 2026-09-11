<?php

namespace App\Console\Commands\Migration;

use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crée un jeu d'icônes SVG professionnelles (style ligne, cohérent avec
 * l'icône générique par défaut déjà utilisée sur la homepage — voir
 * resources/views/home.blade.php, section "Catégories populaires") et les
 * associe à chaque catégorie de l'annuaire (demande client, 11/09/2026).
 *
 * Écrit les fichiers dans `public/images/category-icons/` (asset de design
 * versionné dans le dépôt, comme `public/branding/*` — PAS via le disque
 * `storage/app/public` utilisé pour les uploads admin/utilisateur, voir
 * `App\Models\Concerns\ResolvesImageUrl` qui accepte les deux). Ça laisse un
 * administrateur libre de remplacer l'icône d'une catégorie précise via le
 * champ upload existant (`CategoryResource`) sans toucher au code.
 *
 * Une seule icône peut illustrer plusieurs catégories proches (ex. "food"
 * pour Restaurants/Boucheries/Traiteurs) — c'est le fonctionnement normal
 * d'un système d'icônes par thème, pas 96 dessins bespoke. Quelques
 * catégories volontairement laissées SANS icône (repli sur le cercle
 * générique) : entrées dupliquées/obsolètes (`jardineries-old`) ou
 * catégories où aucune icône neutre pertinente n'a de sens.
 *
 * Écrit via le query builder (PAS Eloquent `->save()`) : `Category` fait
 * partie de `AppServiceProvider::SITEMAP_MODELS` (RegeneratesSitemapObserver)
 * — sans intérêt de déclencher ~90 dispatches pour une simple mise à jour
 * d'icônes, même si `ShouldBeUnique` les déduplique déjà en pratique.
 */
class SeedCategoryIcons extends Command
{
    protected $signature = 'content:seed-category-icons {--dry-run : Affiche ce qui serait changé sans écrire}';

    protected $description = "Génère les icônes SVG des catégories annuaire et les associe à chaque catégorie";

    protected const ICON_DIR = 'images/category-icons';

    /** @var array<string, string> Clé d'icône => contenu SVG (24x24, style ligne, couleur brand-600 #a63f23). */
    protected const ICONS = [
        'takeaway' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 9h12.974c.576 0 1.059.435 1.119 1.007z"/></svg>',
        'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.75" y="5.25" width="16.5" height="15" rx="1.5"/><path d="M3.75 9.75h16.5M8.25 3v3.75M15.75 3v3.75"/></svg>',
        'sofa' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 12.75v-3a2.25 2.25 0 012.25-2.25h10.5a2.25 2.25 0 012.25 2.25v3"/><rect x="3.75" y="12.75" width="16.5" height="6.75" rx="1.5"/><path d="M6.75 16.5h10.5"/></svg>',
        'paw' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="16.5" rx="4" ry="3"/><ellipse cx="6" cy="10.5" rx="1.5" ry="2"/><ellipse cx="9.75" cy="7.5" rx="1.5" ry="2"/><ellipse cx="14.25" cy="7.5" rx="1.5" ry="2"/><ellipse cx="18" cy="10.5" rx="1.5" ry="2"/></svg>',
        'gift' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.75" y="9.75" width="16.5" height="10.5" rx="1"/><path d="M3.75 13.5h16.5M12 9.75v10.5"/><path d="M8.25 9.75C6.5 9.75 5.5 8.5 5.5 7.25S6.5 4.75 8.25 4.75c1.75 0 3.75 2.5 3.75 5"/><path d="M15.75 9.75c1.75 0 2.75-1.25 2.75-2.5s-1-2.5-2.75-2.5C14 4.75 12 7.25 12 9.75"/></svg>',
        'antique' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.75 3.75h4.5M10.5 3.75v2.5c0 .8-.4 1.4-1 2-1.2 1.2-2 3-2 5.25 0 3.5 2 6.75 4.5 6.75s4.5-3.25 4.5-6.75c0-2.25-.8-4.05-2-5.25-.6-.6-1-1.2-1-2v-2.5"/><path d="M7.75 13.5h8.5"/></svg>',
        'palette' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.75c-4.97 0-9 3.7-9 8.25 0 3.6 3 5.25 5.25 5.25.9 0 1.1-.6.7-1.2-.5-.7-.1-1.8 1-1.8h2.8c2.9 0 5.25-2.1 5.25-5.1 0-3-3.15-5.4-6-5.4z"/><circle cx="8.25" cy="10.5" r="0.9" fill="#a63f23" stroke="none"/><circle cx="12" cy="8.25" r="0.9" fill="#a63f23" stroke="none"/><circle cx="15.75" cy="10.5" r="0.9" fill="#a63f23" stroke="none"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8.25" r="2.25"/><circle cx="16.5" cy="9" r="1.875"/><path d="M3.75 19.5c0-2.9 2.35-5.25 5.25-5.25s5.25 2.35 5.25 5.25"/><path d="M15 14.6c1.9.3 3.75 1.9 3.75 4.9"/></svg>',
        'shield' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.25l6.75 2.5v5.25c0 4.5-3 7.75-6.75 9-3.75-1.25-6.75-4.5-6.75-9V5.75z"/><path d="M9 12l2 2 4-4.5"/></svg>',
        'camera' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 8.25a1.5 1.5 0 011.5-1.5h2.1l1.05-1.5h6.9l1.05 1.5h2.1a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-9z"/><circle cx="12" cy="12.75" r="3"/></svg>',
        'car' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 15.75l1.35-4.5A2.25 2.25 0 017.25 9.5h9.5a2.25 2.25 0 012.15 1.75l1.35 4.5"/><path d="M3 15.75h18v2.25a1 1 0 01-1 1h-1.5a1 1 0 01-1-1v-.75H6.5v.75a1 1 0 01-1 1H4a1 1 0 01-1-1v-2.25z"/><circle cx="7" cy="15.75" r="1.25"/><circle cx="17" cy="15.75" r="1.25"/></svg>',
        'briefcase' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.75" y="8.25" width="16.5" height="10.5" rx="1.5"/><path d="M9 8.25v-1.5a1.5 1.5 0 011.5-1.5h3a1.5 1.5 0 011.5 1.5v1.5M3.75 13.5h16.5"/></svg>',
        'glass' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5h9l-1.5 9.75a2.25 2.25 0 01-2.25 1.875h-1.5A2.25 2.25 0 019 14.25L7.5 4.5z"/><path d="M12 16.125V19.5M9 19.5h6"/></svg>',
        'spa' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21c-4-1.5-6-4.75-6-9 0-3 2-5.5 3.75-7.25C11 3.5 12 3 12 3s1 .5 2.25 1.75C16 6.5 18 9 18 12c0 4.25-2 7.5-6 9z"/><path d="M12 21v-9"/></svg>',
        'gem' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5.25h12l3 4.5-9 9.75-9-9.75z"/><path d="M6 5.25l2.25 4.5m9.75-4.5l-2.25 4.5M3 9.75h18M8.25 9.75L12 19.5l3.75-9.75"/></svg>',
        'leaf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5.25 18.75c-1.5-5.25 0-9.75 4.5-12.75 4.5-3 9-1.5 9-1.5s1.5 4.5-1.5 9c-3 4.5-7.5 6-9 6.75-.75-.375-1.5-1.5-3-1.5z"/><path d="M5.25 18.75c3-3 6-6 9.75-10.5"/></svg>',
        'food' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7.5a2.25 2.25 0 002.25 2.25v0a2.25 2.25 0 002.25-2.25V3M6 3v4.5M8.25 3v4.5M6 12.75V21M15.75 3c-1.5 0-2.25 2-2.25 4.5s.75 3.75 2.25 3.75V21"/></svg>',
        'bread' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 15c0-4.5 3-9 7.5-9s7.5 4.5 7.5 9a2.25 2.25 0 01-2.25 2.25h-10.5A2.25 2.25 0 014.5 15z"/><path d="M9 10.5v6M15 10.5v6"/></svg>',
        'wrench' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.25 6.75a4.5 4.5 0 00-6 4.5l-5 5a2 2 0 002.75 2.75l5-5a4.5 4.5 0 004.5-6l-2.5 2.5-2-2 2.5-2.5z"/></svg>',
        'music' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17.25V5.25L19.5 3.75v12"/><circle cx="7.125" cy="17.25" r="2.25"/><circle cx="17.625" cy="15.75" r="2.25"/></svg>',
        'truck' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.25 6.75h11.25v9H2.25zM13.5 10.5h3.75l3 3v2.25h-6.75z"/><circle cx="6" cy="17.25" r="1.5"/><circle cx="16.5" cy="17.25" r="1.5"/></svg>',
        'child' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6" r="2.5"/><path d="M6.75 20.25c0-3.3 2.35-6 5.25-6s5.25 2.7 5.25 6"/></svg>',
        'laptop' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="5.25" width="15" height="10.5" rx="1"/><path d="M2.25 18.75h19.5l-1.5-2.25h-16.5z"/></svg>',
        'controller' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6.75 8.25h10.5a3.75 3.75 0 013.7 4.35l-.5 3a2.25 2.25 0 01-3.85 1.2l-1.35-1.5h-6.5l-1.35 1.5a2.25 2.25 0 01-3.85-1.2l-.5-3a3.75 3.75 0 013.7-4.35z"/><path d="M8.25 10.5v3M6.75 12h3"/><circle cx="16.5" cy="10.9" r=".75" fill="#a63f23" stroke="none"/><circle cx="18" cy="12.4" r=".75" fill="#a63f23" stroke="none"/></svg>',
        'moon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>',
        'book' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5.25c-1.5-1-4-1.5-6.75-1.5v13.5c2.75 0 5.25.5 6.75 1.5m0-13.5c1.5-1 4-1.5 6.75-1.5v13.5c-2.75 0-5.25.5-6.75 1.5m0-13.5v13.5"/></svg>',
        'masks' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8.25" cy="9.75" r="5.25"/><path d="M6 8.25s.75-.75 2.25-.75S10.5 8.25 10.5 8.25M6 11.25s1 1.5 2.25 1.5 2.25-1.5 2.25-1.5"/><circle cx="16.5" cy="14.25" r="4.5"/><path d="M14.6 12.75s.7-.6 1.9-.6 1.9.6 1.9.6M14.25 16.5s.9-1.1 2.25-1.1 2.25 1.1 2.25 1.1"/></svg>',
        'compass' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.25"/><path d="M14.75 9.25l-1.5 4.25-4.25 1.5 1.5-4.25z"/></svg>',
        'glasses' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6.75" cy="14.25" r="3"/><circle cx="17.25" cy="14.25" r="3"/><path d="M9.75 14.25h4.5M2.25 12l1.5-4.5a1.5 1.5 0 011.42-1h1.33M21.75 12l-1.5-4.5a1.5 1.5 0 00-1.42-1h-1.33"/></svg>',
        'scissors' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6.75" r="2.25"/><circle cx="6" cy="17.25" r="2.25"/><path d="M7.75 8.25L19.5 18M7.75 15.75L19.5 6"/></svg>',
        'globe' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.25"/><path d="M3.75 12h16.5"/><path d="M12 3.75c2.25 2.1 3.5 5.1 3.5 8.25s-1.25 6.15-3.5 8.25c-2.25-2.1-3.5-5.1-3.5-8.25S9.75 5.85 12 3.75z"/></svg>',
        'crystal-ball' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10.5" r="6"/><path d="M7.5 19.5h9M9 19.5v-1.5a1 1 0 011-1h4a1 1 0 011 1v1.5"/></svg>',
        'printer' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6.75 8.25V4.5h10.5v3.75"/><rect x="3.75" y="8.25" width="16.5" height="8.25" rx="1"/><rect x="6.75" y="13.5" width="10.5" height="6" rx=".5"/></svg>',
        'sliders' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h13M20 18h0"/><circle cx="13" cy="6" r="1.75" fill="#a63f23" stroke="none"/><circle cx="7" cy="12" r="1.75" fill="#a63f23" stroke="none"/><circle cx="17" cy="18" r="1.75" fill="#a63f23" stroke="none"/></svg>',
        'anchor' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5.25" r="1.5"/><path d="M12 6.75v12M6 12h12M7.5 18c0 1.5 2 3 4.5 3s4.5-1.5 4.5-3"/></svg>',
        'megaphone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 10.5v3a1.5 1.5 0 001.5 1.5h1.5l4.5 3.75v-13.5l-4.5 3.75h-1.5a1.5 1.5 0 00-1.5 1.5z"/><path d="M16.5 9c1 .9 1.5 2 1.5 3s-.5 2.1-1.5 3M19.25 6.75c1.7 1.4 2.5 3.4 2.5 5.25s-.8 3.85-2.5 5.25"/></svg>',
        'home' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5.25 9v9.75a1.5 1.5 0 001.5 1.5h10.5a1.5 1.5 0 001.5-1.5V9"/><path d="M9.75 20.25v-6h4.5v6"/></svg>',
        'bike' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17.5" r="3"/><circle cx="18.5" cy="17.5" r="3"/><path d="M5.5 17.5L9.5 9h4l3 4.5M9.5 9L8 6.5h-2M9.5 9l-4 8.5M13.5 9l5 8.5"/></svg>',
        'sports' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a63f23" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.25"/><path d="M12 3.75v16.5M3.75 12h16.5M6 6.5c2 1.5 4 2 6 2s4-.5 6-2M6 17.5c2-1.5 4-2 6-2s4 .5 6 2"/></svg>',
    ];

    /** @var array<string, string> slug de catégorie => clé d'icône (voir ICONS ci-dessus). */
    protected const SLUG_MAP = [
        'a-emporter' => 'takeaway',
        'a-faire-le-dimanche' => 'calendar',
        'ameublement' => 'sofa',
        'animaux' => 'paw',
        'anniversaires' => 'gift',
        'antiquites-brocantes' => 'antique',
        'art-et-culture' => 'palette',
        'associations' => 'users',
        'assurances' => 'shield',
        'audio-photo-tv-video' => 'camera',
        'auto-ecole' => 'car',
        'auto-moto' => 'car',
        'b-to-b' => 'briefcase',
        'bars' => 'glass',
        'bateaux' => 'anchor',
        'beaute-spa-sauna' => 'spa',
        'bien-etre' => 'spa',
        'bijouteries-et-joailleries' => 'gem',
        'bio-ecologie' => 'leaf',
        'boucheries' => 'food',
        'boulangerie' => 'bread',
        'brasseries' => 'glass',
        'cadeaux' => 'gift',
        'cafe-concert' => 'music',
        'cafe-theatre' => 'masks',
        'coiffure' => 'scissors',
        'com-et-evenements' => 'megaphone',
        'congres-seminaires' => 'briefcase',
        'courses-en-ligne' => 'takeaway',
        'danse' => 'music',
        'debosselage' => 'wrench',
        'decoration' => 'sofa',
        'demenagement' => 'truck',
        'depannage' => 'wrench',
        'dietetique-et-bien-etre' => 'spa',
        'emploi' => 'briefcase',
        'enfants' => 'child',
        'etudiants' => 'book',
        'food-truck' => 'truck',
        'formation-et-coaching' => 'briefcase',
        'formations-et-ecoles' => 'book',
        'gites-chambres-d-hotes' => 'home',
        'gout-et-saveurs' => 'food',
        'habitat-et-deco' => 'sofa',
        'habitat-et-travaux' => 'wrench',
        'hotels' => 'home',
        'immobilier' => 'home',
        'in-english' => 'globe',
        'informatique' => 'laptop',
        'jeux-et-jeux-videos' => 'controller',
        'la-nuit' => 'moon',
        'librairies' => 'book',
        'spectacles' => 'masks',
        'literie' => 'sofa',
        'locations' => 'home',
        'loisirs' => 'compass',
        'loisirs-creatifs' => 'palette',
        'mariage' => 'gift',
        'motoculture' => 'car',
        'musique' => 'music',
        'optique' => 'glasses',
        'photographe' => 'camera',
        'piscines-et-jardin' => 'leaf',
        'plomberie' => 'wrench',
        'pret-a-porter' => 'takeaway',
        'produits-du-terroir' => 'bread',
        'radios' => 'music',
        'relaxation' => 'spa',
        'rencontres' => 'users',
        'restaurants' => 'food',
        'salons-de-the' => 'glass',
        'sante' => 'spa',
        'sciences-et-industrie' => 'laptop',
        'securite' => 'shield',
        'seniors' => 'users',
        'services' => 'sliders',
        'services-a-domicile' => 'sliders',
        'shopping' => 'takeaway',
        'social' => 'users',
        'sports' => 'sports',
        'tatouages-et-piercings' => 'scissors',
        'televiseur' => 'camera',
        'thalasso' => 'spa',
        'tourisme' => 'compass',
        'traiteurs' => 'food',
        'velo' => 'bike',
        'vins' => 'glass',
        'voyance' => 'crystal-ball',
        'theatres' => 'masks',
        'depot-vente' => 'antique',
        'imprimerie' => 'printer',
        'boutique' => 'takeaway',
        'fetes-2023' => 'gift',
        'jardineries' => 'leaf',
        // Volontairement absentes : 'strip-tease' (aucune icône neutre
        // pertinente), 'jardineries-old' (doublon obsolète de 'jardineries').
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $log = new MigrationLog('seed-category-icons');
        $dir = public_path(self::ICON_DIR);

        if (! $dryRun && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (self::ICONS as $key => $svg) {
            $path = "{$dir}/{$key}.svg";
            if (! $dryRun) {
                file_put_contents($path, $svg);
            }
        }
        $this->info(count(self::ICONS)." fichier(s) SVG écrit(s) dans public/".self::ICON_DIR.($dryRun ? ' [dry-run]' : ''));

        $updated = 0;
        $missing = [];

        foreach (self::SLUG_MAP as $slug => $iconKey) {
            $category = DB::table('categories')->where('slug', $slug)->first(['id', 'icon']);

            if (! $category) {
                $missing[] = $slug;

                continue;
            }

            $newIcon = '/'.self::ICON_DIR."/{$iconKey}.svg";
            if ($category->icon === $newIcon) {
                continue;
            }

            $updated++;
            $log->updated("{$slug} -> {$iconKey}");

            if (! $dryRun) {
                DB::table('categories')->where('id', $category->id)->update(['icon' => $newIcon]);
            }
        }

        if ($missing !== []) {
            $log->warn('Slugs non trouvés en base : '.implode(', ', $missing));
        }

        $this->info("{$updated} catégorie(s) mise(s) à jour".($dryRun ? ' [dry-run, rien écrit]' : ''));
        $this->info($log->summary());

        return self::SUCCESS;
    }
}
