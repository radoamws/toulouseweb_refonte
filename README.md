# ToulouseWeb — Refonte

> Portail local dédié à Toulouse et sa région : actualités, agenda, cinéma, annuaire, annonces, sorties et loisirs.
> Ce dépôt contient la **refonte complète** du site en production (https://toulouseweb.com). L'ancien site est conservé en lecture seule dans `old/` pour référence pendant toute la durée du projet — ne pas le modifier.

## État du projet

**Phase actuelle : 1, 2, 3, 4, 5, 8, 9, 10 terminées ; 6, 7, 11, 12, 13 bien avancées ; 14 (déploiement réel) : pipeline CI/CD GitHub Actions prêt (`.github/workflows/deploy.yml`, voir § CI/CD plus bas et `TECHNICAL_DOCUMENTATION.md` §26), premier déploiement réel vers le serveur Infomaniak pas encore effectué depuis cet environnement de développement.**

Le schéma de base cible est appliqué sur `toulouseweb` et **peuplé avec les vraies données de production** (2 978 fiches annuaire, 18 724 événements, 17 304 films, 6 191 actus, 135 sliders...). Toutes les pages publiques (homepage, annuaire, agenda, cinéma, actualités, annonces, contact) et l'administration (21 ressources Filament) sont en ligne et vérifiées. Points marquants récents :

- **Scraper agenda** (`scrape:events`) : les **12 sources réelles de la liste de cron de production** fournie par le client, reconstruites à partir du vrai code legacy et vérifiées en direct — 10/12 pleinement fonctionnelles, 2 avec une limite documentée (sites source refondus depuis le legacy).
- **Scraper cinéma** (`scrape:cinema`) : réécrit, **vérifié en direct** contre les 25 vraies sources AlloCiné (25/25 réussies, 397 séances).
- **Dépôts publics** avec modération stricte non contournable : fiche annuaire, annonce, **et désormais événement** (`/agenda/proposer`).
- **Recherche** : filtre par ville pour l'annuaire (`?city=`) — la vraie recherche géographique par rayon reste hors scope, la base legacy n'ayant jamais stocké de coordonnées.
- **SEO/GEO** : redirections 301 (6 710 entrées), sitemap.xml (4 036 URLs), robots.txt, JSON-LD par domaine, maillage interne sur toutes les pages de détail, gestion des contenus expirés, et `/llms.txt` pour l'optimisation GEO/AI Search.
- **Performance** : audit des requêtes réelles, 2 index manquants corrigés (dont un plein scan systématique sur la table de 2,78M lignes de l'historique de clics).
- **Sécurité** : en-têtes globaux (dont HSTS en production), uploads limités, XSS/CSRF/injection SQL écartés structurellement, honeypot + throttle sur tous les formulaires publics.

Suite de tests : **152 tests / 411 assertions**, tous verts. Voir [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) §0 et §13 pour l'état exact et détaillé du code, y compris la liste des limites connues et hypothèses de mapping à valider avec vous.

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

21 ressources Filament (`/admin`, une par entité — annuaire, agenda, cinéma, actualités, annonces, sliders, contacts, redirections, 404 fréquentes, sources de scraping, **utilisateurs et rôles**...) + une page "Paramètres du site" (`/admin/site-settings` : identité, coordonnées, réseaux sociaux, **SEO/Analytics globaux** — Google Analytics, vérification Search Console) + un dashboard de statistiques de clics (vue d'ensemble, répartition par type, top des entités les plus cliquées — couvre annuaire, agenda, cinéma, actualités, annonces, catégories, sliders, sites partenaires). Voir `TECHNICAL_DOCUMENTATION.md` §13. Documentation détaillée par ressource à poursuivre au fil de l'implémentation.

## Scraping / Cron

Le mécanisme legacy déclenchait les imports via de simples endpoints HTTP GET appelés par un cron externe (pas de scheduler Laravel utilisé) — formalisé ici via le scheduler natif (`routes/console.php`, un seul cron serveur `* * * * * php artisan schedule:run`).

- `php artisan scrape:cinema` — quotidien 5h, une source AlloCiné par salle (25/28) — vérifié en direct (25/25 exécutions réussies, 397 séances).
- `php artisan scrape:events` — quotidien 5h30, une source par site de billetterie/salle (`scraper_sources`, type `agenda`). **12 sources construites**, reproduisant exactement la liste réelle des tâches cron de production fournie par le client (Zenith, Théâtre de la Cité, Casino Théâtre Barrière, Théâtre Garonne, Le Vent des Signes, Odyssud, L'Escale, Théâtre du Grand Rond, Les Grands Interprètes, Toulouse Métropole, Le Bijou, Aria/Cornebarrieu), reconstruites à partir du vrai code legacy et vérifiées en direct contre les vrais sites (10/12 pleinement fonctionnelles, 2 avec une limite connue documentée — sites source ayant changé de CMS depuis l'écriture du legacy). Seedée via `php artisan db:seed --class=AgendaScraperSourcesSeeder`. Détail complet (bugs legacy corrigés, limites connues, résultats de vérification live) : `TECHNICAL_DOCUMENTATION.md` §13.
- `php artisan redirects:audit` — hebdomadaire, 404 fréquentes sans redirection.

## Migration des données

Terminée (Phase 5) — exécutée avec succès contre une vraie copie de `toulouseweb_old`. Pour rejouer sur un nouvel environnement (staging, autre copie de la base legacy) :

```bash
php artisan migrate:reference-data   # catégories, lieux, équipements, référentiels — à lancer en premier
php artisan migrate:listings         # fiches annuaire (extrait aussi ville/code postal depuis l'adresse legacy, ~39% de couverture — voir §13)
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
- **SEO/Analytics globaux** : Google Analytics (GA4) et balise de vérification Search Console configurables dans "Paramètres du site" (`/admin/site-settings`) — chargés uniquement si renseignés, aucun script tiers par défaut.
- **Sitemap** : `php artisan sitemap:generate` régénère `public/sitemap.xml` (planifié quotidiennement). **Ne pas éditer ce fichier à la main**, il est écrasé à chaque exécution.
- **`robots.txt`** : `public/robots.txt`, bloque `/admin` et `/track-click`.
- **Redirections 301** : administrables via `RedirectResource` (`/admin`), servies par `Controller::redirectOrAbort()` sur chaque route de fiche/détail. Voir `TECHNICAL_DOCUMENTATION.md` §13 pour le piège rencontré avec `Route::fallback()` avant ce choix d'implémentation.
- **404 fréquentes** : toute 404 réelle est journalisée (`missed_redirects`) et consultable dans l'admin (`MissedRedirectResource`, "404 fréquentes") ou via `php artisan redirects:audit` (planifié hebdomadairement) — permet de repérer les chemins legacy manquants à traiter manuellement dans `RedirectResource`.
- **Maillage interne** : chaque page de détail (annuaire, agenda, cinéma, actualités, annonces) affiche un bloc "contenu similaire" (même catégorie/salle/à l'affiche).
- **Contenus expirés** : `php artisan content:mark-expired` (planifié quotidiennement) garde le statut des événements/annonces cohérent une fois leur date dépassée — le filtrage public par date, lui, ne dépend pas de cette commande.
- **GEO/AI Search** : `/llms.txt` (convention llmstxt.org) résume le site pour les agents IA — complémentaire de robots.txt (aucun crawler IA bloqué) et des données structurées Schema.org déjà posées sur chaque page.

## Déploiement

Checklist opérationnelle (Phase 14). Pas de déploiement réel effectué depuis cet environnement de développement (pas d'accès à un serveur de production/cPanel) — procédure écrite pour être suivie telle quelle par qui a cet accès. Détail technique et justifications : `TECHNICAL_DOCUMENTATION.md` §14.

### Prérequis serveur

- PHP 8.2+ avec extensions : `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `gd` (ou `imagick`), `zip`, `curl`, `fileinfo`.
- MySQL 8+ (ou MariaDB équivalent).
- Composer 2, Node 18+ (build des assets, pas nécessaire à l'exécution une fois `public/build/` généré).
- Accès à la crontab (un seul cron nécessaire, voir plus bas) — HTTPS obligatoire (certificat valide, brief §18).

### Procédure de mise en production (première fois)

```bash
git clone <dépôt> && cd toulouseweb_refonte
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
# Éditer .env : APP_ENV=production, APP_DEBUG=false, APP_URL=https://toulouseweb.com,
# SESSION_SECURE_COOKIE=true, DB_*, MAIL_*, retirer LEGACY_DB_* une fois la
# migration de données terminée (accès en lecture à l'ancienne base plus utile
# après ce point). Voir les commentaires inline de .env.example.
php artisan key:generate

php artisan migrate --force
php artisan storage:link

# Données de référence + comptes (une fois) :
php artisan db:seed --class=RolesAndAdminSeeder
php artisan db:seed --class=ScraperSourcesSeeder
php artisan db:seed --class=AgendaScraperSourcesSeeder

# Si migration depuis toulouseweb_old pas déjà faite sur cet environnement,
# voir section "Migration des données" plus haut (ordre des commandes important).

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan sitemap:generate
```

Crontab (une seule ligne, le scheduler Laravel gère toutes les tâches planifiées — voir `routes/console.php`) :

```
* * * * * cd /chemin/vers/toulouseweb_refonte && php artisan schedule:run >> /dev/null 2>&1
```

Le worker de file d'attente (`queue:work --stop-when-empty`) tourne DANS ce même cron, minute par minute — pas de processus permanent à superviser (choix adapté à un hébergement mutualisé, voir `routes/console.php`).

### Checklist avant bascule DNS / mise en ligne définitive

- [ ] `APP_ENV=production` et `APP_DEBUG=false` (jamais de stack trace exposée, brief §18).
- [ ] `APP_URL` en `https://`, certificat SSL valide, `SESSION_SECURE_COOKIE=true`.
- [ ] Vérifier les en-têtes de sécurité en conditions réelles (`curl -I https://...` : `Strict-Transport-Security` doit apparaître — n'est envoyé qu'en production, voir `SecurityHeaders`).
- [ ] Compte admin par défaut (`RolesAndAdminSeeder`) : mot de passe changé immédiatement après premher login.
- [ ] `php artisan migrate:redirects` exécutée (6 710 redirections 301 legacy) — indispensable pour ne pas perdre le référencement acquis.
- [ ] `sitemap.xml` généré et accessible, soumis à Google Search Console/Bing Webmaster Tools.
- [ ] Balise de vérification Search Console + Google Analytics renseignées dans "Paramètres du site" (`/admin/site-settings`) si souhaité.
- [ ] `robots.txt` vérifié (bloque `/admin`, `/track-click`).
- [ ] Sources de scraping (`scraper_sources`) vérifiées actives avec les bonnes URLs — voir limites connues §13 (Casino Barrière, Les Grands Interprètes) et saison codée en dur pour Les Grands Interprètes (`InterpreteDriver`, à remettre à jour chaque rentrée).
- [ ] Sauvegardes base de données planifiées côté hébergeur (hors scope applicatif).
- [ ] DNS basculé en dernier, une fois tout ce qui précède vérifié sur le nouveau serveur via son IP/un domaine de test.

### Mises à jour ultérieures (déploiement continu)

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan down --render="errors::503" --retry=60   # optionnel, coupure courte
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan up
```

### CI/CD (GitHub Actions) — déploiement automatique depuis `main`

Depuis le 08/09/2026 (demande client), tout push sur `main` déploie automatiquement vers le serveur de production Infomaniak. Fichier : `.github/workflows/deploy.yml`. Détail/justifications des choix : `TECHNICAL_DOCUMENTATION.md` §26.

**Ce que fait le workflow** (déclenché aussi manuellement : onglet GitHub "Actions" → "Déploiement production (Infomaniak)" → "Run workflow") :
1. Build côté CI : `composer install --no-dev`, `npm ci && npm run build`.
2. Synchronisation (`rsync --delete` par SSH) vers le serveur — **sauf** `.env`, `storage/app`, `storage/logs`, `public/storage` (voir commentaires du workflow : ce sont les seuls éléments persistants, jamais écrasés par un déploiement).
3. Par SSH sur le serveur : `artisan down` (bref, le temps des migrations) → `migrate --force` → `storage:link` → `config:cache`/`route:cache`/`view:cache`/`event:cache` → `queue:restart` → `artisan up`.

**Secrets GitHub à créer une fois** (Settings du dépôt → Secrets and variables → Actions → New repository secret) :

| Secret | Contenu |
|---|---|
| `DEPLOY_SSH_HOST` | Hôte SSH du serveur Infomaniak |
| `DEPLOY_SSH_PORT` | Port SSH (souvent différent de 22 en mutualisé — à vérifier dans le manager Infomaniak) |
| `DEPLOY_SSH_USER` | Utilisateur SSH (non-root) |
| `DEPLOY_SSH_PRIVATE_KEY` | Clé privée SSH **dédiée au déploiement** (voir génération ci-dessous) — jamais la clé personnelle |
| `DEPLOY_PATH` | Chemin absolu du site, ex. `/sites/toulouseweb.com` |
| `DEPLOY_PHP_BIN` *(optionnel)* | Chemin du binaire PHP si `php` seul ne résout pas vers PHP 8.2+ dans une session SSH non-interactive (`ssh user@host 'which php; php -v'` pour vérifier) |

Génération de la clé dédiée (en local, jamais partagée) :
```bash
ssh-keygen -t ed25519 -C "deploy-toulouseweb" -f deploy_key -N ""
# deploy_key.pub  -> à ajouter dans ~/.ssh/authorized_keys de l'utilisateur SSH sur le serveur
# deploy_key      -> contenu intégral collé dans le secret GitHub DEPLOY_SSH_PRIVATE_KEY
```

**Checklist unique avant le tout premier déploiement automatique** (le workflow ne gère QUE le code — pas les données ni la configuration serveur) :

- [ ] Racine du document du site réglée, dans le manager Infomaniak, sur `<DEPLOY_PATH>/public` (pas `<DEPLOY_PATH>` lui-même).
- [ ] `.env` créé À LA MAIN sur le serveur (copié depuis `.env.example`, jamais généré/écrasé par le CI) : `APP_KEY` généré une fois (`php artisan key:generate --show`), `APP_URL=https://043e6cgnrn.preview.infomaniak.website` (puis `https://toulouseweb.com` après bascule DNS), `DB_*` avec les identifiants de la base déjà créée, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` — voir checklist complète plus haut.
- [ ] Squelette `storage/` créé une fois à la main (exclu du rsync, donc jamais recréé automatiquement) :
  ```bash
  mkdir -p <DEPLOY_PATH>/storage/app/public <DEPLOY_PATH>/storage/logs
  ```
- [ ] Données de production transférées une fois vers la base vide déjà créée (le CI/CD ne déploie que du CODE, jamais de données) : export de la base locale déjà migrée/vérifiée (`mysqldump toulouseweb > dump.sql`) puis import dans la base Infomaniak (`mysql` en SSH, ou l'outil d'import du manager Infomaniak).
- [ ] Médias déjà résolus (`storage/app/public/...`, voir §22/§23) transférés une fois par `rsync`/`scp` séparé vers `<DEPLOY_PATH>/storage/app/public/` sur le serveur (le rsync du CI exclut délibérément `storage/app`, voir ci-dessus).
- [ ] Cron unique enregistré côté Infomaniak (souvent via l'interface du manager plutôt qu'un `crontab -e` brut sur ce type d'hébergement — vérifier) :
  ```
  * * * * * cd <DEPLOY_PATH> && php artisan schedule:run >> /dev/null 2>&1
  ```
- [ ] Premier push sur `main` (ou déclenchement manuel) : suivre l'exécution dans l'onglet "Actions" du dépôt.

**Rollback** : `git revert <commit>` + push sur `main` (redéploie automatiquement l'état précédent), ou ré-exécuter manuellement le workflow sur un commit/tag antérieur (bouton "Run workflow", champ "Use workflow from"). Les migrations de ce projet sont additives — un rollback de code reste presque toujours compatible avec le schéma déjà en place, voir `TECHNICAL_DOCUMENTATION.md` §14.

### Rollback (déploiement manuel, sans CI/CD)

`git checkout <tag/commit précédent>` + rejouer `composer install`/`npm run build`/caches. Les migrations de ce projet sont additives (nouvelles colonnes/tables/index, jamais de suppression destructive sans migration `down()` correspondante) — un rollback de code sans rollback de schéma reste presque toujours compatible ; vérifier au cas par cas avant un rollback qui traverserait une migration non additive.
