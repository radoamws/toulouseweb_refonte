# ToulouseWeb — Refonte

> Portail local dédié à Toulouse et sa région : actualités, agenda, cinéma, annuaire, annonces, sorties et loisirs.
> Ce dépôt contient la **refonte complète** du site en production (https://toulouseweb.com). L'ancien site est conservé en lecture seule dans `old/` pour référence pendant toute la durée du projet — ne pas le modifier.

## État du projet

**Phase actuelle : 5, 6 et 8 terminées ; 7/9-12 et 3/4/10 bien avancées.**
Le schéma de base cible est appliqué sur `toulouseweb` et **peuplé avec les vraies données de production** (2 978 fiches annuaire, 18 724 événements, 17 304 films, 6 191 actus, 135 sliders...). L'administration (18 ressources Filament) et les pages publiques principales sont en ligne et vérifiées : homepage, annuaire, agenda (dont la catégorie Théâtre sur sa propre URL), cinéma, actualités, annonces (dépôt public avec modération non contournable), contact. Les redirections 301 (6 710 entrées migrées) et le sitemap.xml (4 036 URLs) sont opérationnels. Le scraper cinéma (`scrape:cinema`, une source AlloCiné par salle — 24 des 27 salles legacy, dont Pathé-Gaumont Wilson qui n'a pas de traitement spécial) est réécrit, gère films **et horaires précis**, et est planifié quotidiennement — **non re-vérifié en direct** faute d'accès réseau sortant depuis cet environnement de développement, à confirmer dès la première exécution en production. Une première passe de sécurité corrige les principales failles constatées dans l'ancien système à l'audit. Les images de contenu (annuaire, films, actualités, agenda, sliders) ont été réimportées depuis `old/backEnd/public/` (33 000+ fichiers retrouvés, correction d'une estimation initiale erronée à seulement 48 — voir §13) ; l'annuaire affiche désormais photo principale et galerie réelles. Une page "Paramètres du site" administre désormais le nom, la description, le logo et les réseaux sociaux (JSON-LD Organization, meta OG, pied de page), auparavant en dur dans le layout. Le dépôt public de fiche annuaire (`/annuaire/deposer`) est en ligne, sur le même modèle de modération stricte que les annonces (toujours `pending`/gratuite, jamais publiée automatiquement). Investigation terminée sur le scraper agenda : **aucun mécanisme fonctionnel n'existe côté legacy** (routes mortes, voir §13) — construire un scraper agenda signifierait partir de zéro, décision produit en attente. Ce qui manque encore : calendrier visuel, performance à grande échelle, déploiement. Voir [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) §0 et §13 pour l'état exact et détaillé du code, y compris la liste des hypothèses de mapping à valider avec vous.

Avant de reprendre ce projet dans une nouvelle session : lire ce fichier, lire `TECHNICAL_DOCUMENTATION.md`, puis regarder l'état réel du code (`git log`, arborescence) avant de continuer — ne jamais repartir de zéro sur une fonctionnalité déjà faite.

## Résumé de l'audit (Phase 1)

L'ancien site repose sur Laravel 7 (API) + Nuxt 2 (Vue 2, SSR). La base `toulouseweb_old` contient ~80 tables. Points clés remontés par l'audit :

- **Un système de tracking de clics existe déjà** (`t_stat_counter`, 2,78M lignes, actif depuis 2021) et sert de socle au tableau de bord statistiques étendu demandé (clics sur bannières, catégories, encadrés, fiches ciné/films, etc.).
- **Un module social complet "Rencontres/Toulousains"** (site de rencontres avec profils, messagerie privée, notation, sorties) tourne depuis 2004 sous la même API, sans être mentionné dans le brief initial — décision produit nécessaire (voir Journal de décisions ci-dessous).
- **Sécurité legacy critique** : injection SQL systémique, back-office générique (`BOController`) sans authentification ni whitelist de tables, mots de passe non salés — à ne surtout pas reproduire dans la refonte.
- **SEO à reconstruire presque entièrement** : pas de canonical/OpenGraph, pas de sitemap fiable, pas de table de redirections ancien→nouveau site exploitable.
- **Scraping cinéma actuellement cassé** (l'insertion des séances est commentée dans le code legacy) — à réécrire complètement, source réelle = API Pathé-Gaumont (pas Allociné).

Détail complet, table par table et contrôleur par contrôleur : voir `TECHNICAL_DOCUMENTATION.md`.

## Journal de décisions produit/architecture

| Date | Décision | Choix |
|---|---|---|
| 2026-08-23 | Stack technique cible | **Laravel monolithe + Filament** (Blade/Alpine/Tailwind, pas de SPA séparée, pas de process Node en prod) |
| 2026-08-23 | Devenir du module Rencontres/Toulousains | **Archivé, non relancé** — export RGPD en lecture seule, fonctionnalité non reconstruite dans la refonte |
| 2026-08-23 | Environnement d'hébergement cible | **Mutualisé/cPanel** — pas de Redis/Node permanent supposés disponibles ; cron via tâche planifiée cPanel, queue/cache/session en driver `database` |

## Fonctionnalités cibles (rappel du brief)

- Homepage moderne avec slider administrable, mise en avant des contenus (actus, agenda, ciné, annuaire, annonces, sponsors).
- Annuaire (fiches payantes détaillées + fiches gratuites minimales), recherche par catégorie/mot-clé/zone géographique, catégories dont Enfants/Sports/Mariages/Restaurants (spécificités restaurant à conserver).
- Agenda/événements avec catégorie Théâtre dédiée (même moteur, filtre catégorie), calendrier, filtres, fiche détaillée.
- Scraping événements et cinéma, robuste, sans doublons, documenté pour cron de production.
- Annonces avec workflow de modération strict (utilisateur → en attente → validation admin → publication).
- Contact repensé, sécurisé, SEO.
- Administration complète de toutes les entités (voir brief `task.txt` pour la liste exhaustive), y compris **statistiques de clics étendues sur tout le site** (bannières, catégories, encadrés, cinéma, films...).
- SEO/GEO priorité absolue : URLs propres et stables, redirections 301, title/description/canonical/OG/Twitter Cards administrables avec valeurs par défaut intelligentes, sitemap XML, robots.txt, breadcrumbs, données structurées Schema.org (événements, LocalBusiness, restaurants, articles), maillage interne, optimisation recherche locale/IA.
- Migration complète des données utiles de `toulouseweb_old` vers `toulouseweb` avec transformation/normalisation (pas une simple copie).
- Design system complet, mobile-first, moderne et premium.
- Sécurité intégrée dès la conception (validation, CSRF/XSS/SQLi, uploads sécurisés, permissions).

## Installation / Configuration

Prérequis : PHP 8.2+, Composer 2, Node 20+/npm, MySQL/MariaDB (XAMPP en local).

```bash
composer install
npm install
cp .env.example .env   # déjà fait en local — adapter DB_* / LEGACY_DB_* si besoin
php artisan key:generate
php artisan migrate
php artisan db:seed --class=RolesAndAdminSeeder   # crée les rôles + le compte super-admin
npm run build   # ou `npm run dev` pendant le développement
php artisan serve
```

- Base de données locale : MySQL/MariaDB via XAMPP, host `localhost`, user `root`, sans mot de passe.
  - `toulouseweb_old` : données de l'ancien site (lecture seule, source de migration, connexion Laravel nommée `legacy`).
  - `toulouseweb` : base cible de la refonte, porte déjà le schéma complet (voir `TECHNICAL_DOCUMENTATION.md` §9).
- Ancien site de référence : `old/` (ne pas modifier, non versionné — voir `.gitignore`).
- Administration : `http://localhost:8000/admin` — connexion avec le compte créé par `RolesAndAdminSeeder` (mot de passe temporaire à changer immédiatement, voir `TECHNICAL_DOCUMENTATION.md` §13).
- Tests : `php artisan test` (tourne sur SQLite en mémoire, n'impacte jamais `toulouseweb`).

## Administration

18 ressources Filament (`/admin`, une par entité — annuaire, agenda, cinéma, actualités, annonces, sliders, contacts, redirections, sources de scraping...) + une page "Paramètres du site" (`/admin/site-settings` : nom, description, logo, coordonnées, réseaux sociaux — alimente le JSON-LD Organization et le pied de page, voir `TECHNICAL_DOCUMENTATION.md` §13). Documentation détaillée par ressource à poursuivre au fil de l'implémentation.

## Scraping / Cron

_À documenter au fur et à mesure de l'implémentation (Phase 7 pour les événements, Phase 8 pour le cinéma)._ Le mécanisme legacy déclenchait les imports via de simples endpoints HTTP GET appelés par un cron externe (pas de scheduler Laravel utilisé) — la refonte formalisera cela proprement (scheduler natif + commandes documentées, voir §21 du brief).

## Migration des données

Terminée (Phase 5) — exécutée avec succès contre une vraie copie de `toulouseweb_old`. Pour rejouer sur un nouvel environnement (staging, autre copie de la base legacy) :

```bash
php artisan migrate:reference-data   # catégories, lieux, équipements, référentiels — à lancer en premier
php artisan migrate:listings         # fiches annuaire
php artisan migrate:events           # agenda
php artisan migrate:cinema           # salles, films, séances, horaires, commentaires
php artisan migrate:news             # actualités + commentaires
php artisan migrate:sliders          # sliders + emplacements
php artisan migrate:contacts         # messages de contact actifs
php artisan migrate:partner-sites    # sites partenaires (page contact)
php artisan migrate:seo              # métadonnées SEO personnalisées
php artisan migrate:redirects        # amorce des redirections 301
php artisan migrate:click-stats --truncate   # historique de clics (~2,78M lignes, la plus longue — plusieurs minutes)
```

Toutes ces commandes sont idempotentes (rejouables sans dupliquer), sauf `migrate:click-stats` qui nécessite `--truncate` pour être relancée. Logs détaillés dans `storage/logs/migration/<domaine>.log`. Détail complet (mapping des champs, hypothèses de statut, corrections de schéma découvertes en cours de route) : `TECHNICAL_DOCUMENTATION.md` §10 et §13.

### Réimport des images de contenu

`old/backEnd/public/` contient plus de 33 000 fichiers image (~3,2 Go) correspondant aux fiches annuaire, films, actualités, agenda et sliders. Ces commandes retrouvent et copient ceux qui correspondent encore à une ligne migrée, puis mettent à jour le modèle correspondant (ou une collection MediaLibrary pour l'annuaire) :

```bash
php artisan images:movies         # affiches de films (movies.poster)
php artisan images:news           # images d'actualités (news.image)
php artisan images:listings       # fiches annuaire : photo principale (media "logo") + galerie (media "gallery")
php artisan images:events         # images d'événements (events.image)
php artisan images:amenities      # pictogrammes d'équipements (amenities.icon)
php artisan images:partner-sites  # logos des sites partenaires (partner_sites.logo) — après migrate:partner-sites
php artisan images:sliders        # images de sliders (recherche large, peu de correspondances)
```

**Ces commandes ne sont volontairement jamais rejouées en committant leur résultat dans git** (des centaines de Mo de binaires, aucun intérêt de diff) — `storage/app/public/` reste ignoré comme par défaut. Sur un nouvel environnement (staging, production), les rejouer contre une copie de `old/backEnd/public/` (ou tout accès équivalent au stockage de l'ancien site), exactement comme les commandes `migrate:*` contre une copie de `toulouseweb_old`. Détail des volumes réellement récupérés par domaine : `TECHNICAL_DOCUMENTATION.md` §13.

## SEO / GEO

- **Balises** : title/description personnalisables par entité (`seo_meta`) avec génération automatique de repli (`App\Services\Seo\SeoResolverService`), canonical, Open Graph, Twitter Card, JSON-LD (Organization, LocalBusiness/Restaurant, Event, Movie/MovieTheater, NewsArticle, BreadcrumbList) — voir `resources/views/components/layouts/app.blade.php` et les vues de détail par domaine.
- **Sitemap** : `php artisan sitemap:generate` régénère `public/sitemap.xml` (planifié quotidiennement). **Ne pas éditer ce fichier à la main**, il est écrasé à chaque exécution.
- **`robots.txt`** : `public/robots.txt`, bloque `/admin` et `/track-click`.
- **Redirections 301** : administrables via `RedirectResource` (`/admin`), servies par `Controller::redirectOrAbort()` sur chaque route de fiche/détail. Voir `TECHNICAL_DOCUMENTATION.md` §13 pour le piège rencontré avec `Route::fallback()` avant ce choix d'implémentation.

## Déploiement

_À documenter en Phase 14._
