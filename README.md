# ToulouseWeb — Refonte

> Portail local dédié à Toulouse et sa région : actualités, agenda, cinéma, annuaire, annonces, sorties et loisirs.
> Ce dépôt contient la **refonte complète** du site en production (https://toulouseweb.com). L'ancien site est conservé en lecture seule dans `old/` pour référence pendant toute la durée du projet — ne pas le modifier.

## État du projet

**Phase actuelle : 3/4/10 — design system, administration et homepage bien avancés.**
Le schéma de base cible est appliqué sur `toulouseweb`, 18 ressources d'administration Filament existent et sont testées, et une vraie homepage (slider, actus, agenda, cinéma, annuaire, annonces) est en ligne avec le design system (palette "Ville Rose", typographies Outfit/Inter). Les pages de contenu par domaine (fiche annuaire, agenda, cinéma, annonces, contact) n'existent pas encore, ni scraper, ni migration de données réelle (le contenu actuel est un jeu de démo fictif, voir `DemoContentSeeder`). Voir [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) §0 et §13 pour l'état exact et détaillé du code.

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

_À documenter au fur et à mesure de l'implémentation (Phase 4)._

## Scraping / Cron

_À documenter au fur et à mesure de l'implémentation (Phase 7 pour les événements, Phase 8 pour le cinéma)._ Le mécanisme legacy déclenchait les imports via de simples endpoints HTTP GET appelés par un cron externe (pas de scheduler Laravel utilisé) — la refonte formalisera cela proprement (scheduler natif + commandes documentées, voir §21 du brief).

## Migration des données

_Plan détaillé à produire en Phase 2, exécution en Phase 5._ Voir `TECHNICAL_DOCUMENTATION.md` §3 pour l'inventaire complet des tables à migrer, à transformer, ou à exclure.

## SEO / GEO

_Stratégie détaillée à produire en Phase 2, implémentation en Phase 11._

## Déploiement

_À documenter en Phase 14._
