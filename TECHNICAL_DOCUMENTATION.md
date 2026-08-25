# ToulouseWeb — Documentation technique

> Document vivant : à mettre à jour à chaque modification importante du code ou de l'architecture.
> Dernière mise à jour : 2026-08-25 — **Phases 1, 2, 5, 6, 8 terminées ; Phase 3 (design system + layout) et Phase 4 (admin, dont page Paramètres du site) bien avancées ; premières briques de la Phase 10 (homepage) livrées.**

---

## 0. État du projet

| Phase | Statut |
|---|---|
| 1. Audit complet de l'ancien site et de la base | ✅ Terminé (§1-6) |
| 2. Architecture technique et base de données | ✅ Terminé (§7-11) |
| 3. Design system & layout | 🟡 Palette/typographies/composants Blade de base livrés (§13), pages de contenu (annuaire/agenda/cinéma...) pas encore construites |
| 4. Administration | 🟡 21 ressources Filament créées et testées (dont gestion utilisateurs/rôles) + relation manager Séances (Phase 8) + page Paramètres du site (dont SEO/Analytics globaux) + dashboard stats de clics (§13) |
| 5. Migration des données | ✅ Terminé — 11 commandes `migrate:*` exécutées avec succès contre `toulouseweb_old` réelle (§13, dont `migrate:partner-sites` ajoutée le 2026-08-24 — table oubliée à l'audit initial) + 7 commandes `images:*` ayant réimporté l'écrasante majorité des visuels de contenu retrouvés sous `old/backEnd/public/` (33 000+ fichiers, voir §13) |
| 6. Annuaire | 🟡 Pages publiques (index par catégorie + recherche, fiche détail) livrées et vérifiées avec les vraies données, désormais avec photo principale + galerie réelles + dépôt public de fiche (modération stricte, tier toujours gratuit) — voir §13 ; pas encore de recherche géographique |
| 7. Agenda / événements / théâtre | 🟡 Pages publiques (index + filtre catégorie dont "theatre", fiche détail, **calendrier visuel**) livrées ; proposition d'événement par le public pas encore faite ; scraper agenda confirmé **inexistant côté legacy** (routes mortes, aucune méthode réelle — voir §13), décision produit requise avant de construire quoi que ce soit |
| 8. Cinéma | 🟡 Pages publiques + scraper AlloCiné réécrit (`scrape:cinema`, une source par salle — 24/27 — fiches film + salle + horaires précis, planifié quotidien) + relation manager Séances (saisie manuelle en complément) ; scraper non re-vérifié en direct (accès réseau bloqué depuis ce sandbox, voir §13) |
| 9. Annonces | 🟡 Pages publiques + dépôt avec workflow de modération strict (jamais de publication automatique, honeypot anti-spam) livrés et testés (§13) |
| 10. Homepage | 🟡 Fonctionnelle et vérifiée avec les vraies données migrées (slider, actus, agenda, cinéma, annuaire, annonces désormais dépôt-able) |
| Contact (brief §11, hors numérotation de phase) | ✅ Page refaite, formulaire sécurisé (honeypot + throttle), stockage dans `contact_messages` déjà administrable |
| 11. SEO/GEO, URLs, redirections | 🟡 Redirections 301 opérationnelles sur les 6 710 entrées migrées (annuaire/cinéma/actualités/catégories), sitemap.xml généré (4 036 URLs), robots.txt ; canonical/OG/Twitter/JSON-LD déjà posés depuis les phases précédentes |
| 12. Performance & sécurité | 🟡 Amorce sécurité : en-têtes de sécurité globaux, limites d'upload Filament, cookies de session sécurisés en prod, tests dédiés (§13). Performance (cache, index) pas encore traitée |
| 13-14 | Non démarrées |

Le dossier `old/` contient l'ancien site (backend Laravel 7 + frontend Nuxt 2), conservé en lecture seule pour référence. La base `toulouseweb_old` contient les données de production, non migrées. La base `toulouseweb` porte désormais le **schéma cible complet** (§9) et un compte admin. Voir §14 pour le détail de ce qui est réellement codé à date.

---

## 1. Stack de l'ancien site (audité, en lecture seule)

- **Backend** : `old/backEnd` — Laravel 7, PHP ^7.2.5. Utilisé en pur mode API (`routes/api.php`, 256 lignes). Aucun Eloquent Model (sauf `Mailer.php` inutilisé comme tel) : tout passe par `DB::table()`/`DB::raw()`.
- **Frontend** : `old/client-app` — Nuxt 2.15.7 (Vue 2), SSR via `server/index.js` (Express) + PM2, génération statique (`nuxt generate`) pour la prod, `bootstrap-vue`, TinyMCE, vue-gtag.
- **Base de données** : MariaDB 10.4, base `toulouseweb_old`, ~80 tables, mélange InnoDB/utf8mb4 (code récent) et MyISAM/latin1 (code ancien, pré-Laravel).
- **Admin** : SPA Nuxt (`client-app/pages/admin/*.vue`), une page par entité, pas de composant CRUD réutilisable (dupliqué à la main partout).
- **Hébergement observé** : `old/htaccess_admin` référence un chemin `public_html/toulouseweb` (hébergement mutualisé probable), `old/backup/.env.*` montre des environnements `development/preprod/production`.

## 2. Cartographie fonctionnelle de l'ancien site

### 2.1 Modules décrits dans le brief

| Module | Contrôleur(s) | Tables principales | État |
|---|---|---|---|
| Annuaire (fiches pro) | CategoryController, ArticleController, IconeController | `t_category`, `t_article`, `t_art_categ`, `t_carousel`, `t_encadre_icone`, `t_icone` | Fonctionnel, bien pensé (3 niveaux de catégories), mais dupliqué (Category/Article) et injectable |
| Agenda / Événements (dont Théâtre = catégorie) | AgendaController | `t_agendas`, `t_agenda_categories`, `t_agenda_cat`, `t_areas` | Fonctionnel mais FK `id_area` mal déclarée (pointe sur elle-même), imports par cron externe |
| Cinéma | CinemaController | `t_cine`, `t_cine_film`, `t_cine_projection`, `t_cine_proj_heures`, `t_cine_proj_types`, `t_cine_type_projection`, `t_cine_lang`, `t_cine_comment` | Modèle de données correct ; **scraping actuellement cassé** (INSERT commenté) |
| Annonces | AnnonceController | `t_annonce`, `t_annonce_category`, `t_annonce_categ` | Quasi jamais utilisé en prod (3 lignes de test) ; frontend actuel = iframe vers un WordPress externe (`annonces.toulouseweb.com`), pas un vrai module Nuxt |
| Restaurants | Sous-catégorie de l'annuaire | `t_article.categ_resto` + `t_category` | Spécificités : champ `categ_resto`, colonnes réservation/click&collect (`lien_resto1..4`, `lien_clickcollect`) rarement remplies mais à conserver |
| Contact | ContactController | `t_contacts` (legacy, obsolète), `t_contact_us` (actif), `t_contact_sites` | Email de notification actuellement désactivé (commenté) |
| SEO (title/description/canonical par entité) | SharedController, EntetesController | `t_seo_entity`, `t_seo_groupe`, `t_entete` (`toulou_entete` = doublon obsolète) | Bon principe (groupe/entité), fallback par pré-remplissage en masse (pas de vrai fallback dynamique) |
| Sitemap | SiteMapController | `t_sitemap`, `gsc_suppression`, `t_sc_all_urls` | Généré à la volée sans cache à chaque requête ; filtre de statut incohérent selon les entités |
| Statistiques de clics | StatController | `t_stat_counter` (2,78M lignes), `t_stat_entites` | **Déjà existant et actif depuis 2021** — voir §2.3 |
| Sliders (homepage) | SharedController | `t_sliders`, `t_slider_place`, `t_slider_page` | Fonctionnel, à conserver |
| Administration | BOController (générique), + contrôleurs dédiés | toutes tables `t_*` | CRUD générique dangereux, voir §4 |

### 2.2 Module non mentionné dans le brief : « Rencontres / Toulousains »

Un site de rencontres amoureuses complet, actif depuis 2004, greffé sous la même API :

- **Profils** (`t_toulousains`, 700 lignes) : pseudo, email, mot de passe (SHA1 non salé), sexe, résidence, année de naissance, bio, préférences, alertes sorties, statut actif/inactif.
- **Messagerie privée** (`t_courriers`, 3 053 lignes, depuis 2004) via `MessagesController`.
- **Notation d'attractivité "côte d'amour"** (`t_cote_d_amour`) et favoris (`t_favoris`) via `CoteController`.
- **Sorties IRL** (`t_sorties`/`t_type_sorties`/`t_alert_sorties`) via `SortiesController` — modèle prêt mais quasiment jamais utilisé en prod (0 vraie ligne).
- **Statuts éphémères type réseau social** (`t_humeurs`) via `HummeursController` — jamais adopté (1 ligne de test).
- **Soirées + albums photo** (`t_soires`/`t_soiree_images`) via `SoireesController` — jamais adopté.
- **Sondages** (`t_sondages`) via `SondageController` — figé, 2 lignes.
- Admin dédiée côté Nuxt : `pages/admin/rencontre.vue`, `pages/admin/forum.vue`.

**Risques majeurs** : mots de passe SHA1 non salés, token de validation de compte = `base64_encode($email)` (trivialement forgeable), `my_profile/{id}` renvoie `SELECT *` (fuite du hash + IP), aucune route protégée par middleware, données personnelles sensibles (bio intime, IP, emails) sans mention RGPD. **Décision produit nécessaire avant la Phase 2** — voir §7.

### 2.3 Système de statistiques de clics existant (base du futur tableau de bord demandé)

Mécanisme déjà en place et à faire évoluer plutôt qu'à recréer de zéro :

```
t_stat_entites (dictionnaire) : id | name | table_name
t_stat_counter (2 779 087 lignes, alimentée en continu depuis 2021-03-23) :
  id | id_stat_entite | id_sous_categ | id_entite | date
```
- Polymorphisme simple : `id_stat_entite` identifie le type d'objet cliqué, `id_entite` son id réel.
- Types réellement utilisés en prod : rubrique/catégorie (2,05M clics), encadré/fiche annuaire (213k), agenda (176k), accueil (175k), news (160k), sliders (6,6k).
- Types déclarés mais jamais utilisés : annonces, cinéma, forum, contact — et 2 des `table_name` déclarés (`t_menu`, `t_annonces`, `t_contact`) ne correspondent à aucune table réelle.
- **Limite actuelle** : pas d'IP, pas de referrer, pas de user-agent, pas de session — un simple compteur d'événements.
- **Demande explicite du client** : « chaque clic peu importe où doit être ajouté dans cette statistique (clic bannière, catégorie, encadrés, ciné, film, ...) » → le nouveau modèle doit être un **event tracking générique** (`entity_type`, `entity_id`, `context` optionnel, `clicked_at`, + IP/hash, referrer, user-agent, session) exposé par un seul endpoint `POST /api/track-click`, appelable depuis n'importe quel composant frontend, avec dashboard de synthèse par type/période dans l'admin.

## 3. Base de données `toulouseweb_old` — audit complet

Rapport complet disponible dans l'historique d'audit (agent DB) — résumé actionnable ici.

### 3.1 Qualité générale des données

- **Deux générations de schéma coexistent** : tables InnoDB/utf8mb4 (récentes) et MyISAM/latin1 (héritées d'avant Laravel).
- **Mojibake** : caractères mal encodés y compris dans des tables utf8mb4 (`t_article.adresse`, `sous_titre`...) → nécessite un nettoyage colonne par colonne, pas une simple conversion de charset globale.
- **FK logiques cassées** : `t_agendas.id_area` référence `t_agendas(id)` au lieu de `t_areas(id)` ; `t_sorties.id_type` référence `t_toulousains(id)` au lieu de `t_type_sorties(id)`.
- **Données orphelines** : 426 lignes `t_agendas` avec `id_area` orphelin, 1 801 lignes `t_art_categ` avec `id_article` orphelin, 27 lignes `t_carousel` orphelines.
- **Dates invalides** : `0000-00-00 00:00:00`, `0001-01-01`, `date_created=2147483647` (bug `INT_MAX`).
- **Statuts non normalisés** : chaque table réinvente son propre système (`tinyint` 0/1/2, `char(1)` T/F/D/S...).
- **Doublons de tables à trancher** : `toulou_entete` (obsolète) vs `t_entete` (actuel) ; `annuaire` (ancien annuaire de liens web 2008, obsolète) vs `t_article`/`t_category` (actuel) ; `t_favoris` (actif) vs `t_toulousains_favoris` (mort) ; `t_contacts` (obsolète, 2001-2014) vs `t_contact_us` (actif) ; `t_user` (obsolète, MD5) vs `users` (déjà prêt, structure Laravel standard, vide).

### 3.2 Tables à NE PAS migrer (obsolètes/mortes — ~1,3M lignes à exclure)

`t_cine_proj_heures_bkp`, `t_cine_proj_types_bkp`, `t_cine_projection_bkp` (snapshots historiques, plages d'ID disjointes des tables actives), `t_sitemap_tmp`, `t_sitemap_tmp_manual`, `t_sitemap_test`, `t_sitemap_inc`, `t_historique_indexation`, `toulou_entete`, `annuaire`, `t_contacts`, `email_validation`, `t_sondages`, `t_toulousains_favoris`, `t_humeurs`, `t_soires`, `t_soiree_images`, `t_sorties`, `t_sortie_interssant`, `t_scrapping_tmp`, `t_scrapping_ba`, `t_user`, `t_article_prod`, `t_annonce*` (3 lignes de test).

### 3.3 Domaines bien modélisés (migration directe possible après nettoyage)

Cinéma (`t_cine*`), sliders (`t_sliders`/`t_slider_place`/`t_slider_page`), icônes (`t_icone`/`t_encadre_icone`), SEO (`t_seo_entity`/`t_seo_groupe`), sitemap/indexation (`t_sitemap`/`gsc_suppression`).

### 3.4 Risques RGPD

`t_toulousains` (photos, préférences intimes, IP), `t_courriers` (messagerie privée, IP), `t_nbconnections`/`t_nb_visites` (IP), `t_contact_us` (coordonnées). Politique de rétention/anonymisation à définir avant migration, en particulier pour les comptes très anciens (2004) probablement abandonnés.

## 4. Sécurité — constats de l'audit backend (à corriger intégralement dans la refonte)

Classés par gravité :

1. **Injection SQL systémique** : quasiment tous les contrôleurs concatènent des paramètres de route directement dans du SQL brut (`CategoryController`, `ArticleController`, `AgendaController`, `AnnonceController`, `CinemaController`, `NewsController`, `SharedController::login`, `BOController::getStruct`, `StatController`, tout le module Rencontres).
2. **`BOController` = CRUD générique sans whitelist ni auth** sur `{table}` : lecture/écriture/suppression possible sur n'importe quelle table `t_*` de la base, combiné à l'injection SQL. Risque n°1 de tout le projet legacy.
3. **Aucune authentification API réelle** : pas de middleware `auth` sur les routes d'écriture, sessions PHP natives (`session_start()`) non revérifiées par les contrôleurs métier, tokens non signés (`uniqid()`, `base64_encode($email)`).
4. **Mots de passe faibles** : MD5 non salé (admin `t_user`), SHA1 non salé (`t_toulousains`).
5. **Path traversal potentiel** : `SharedController::uploadFile` (nom de fichier non sanitizé) et `SharedController::getContent` (paramètre `path` non validé, lecture arbitraire de fichier possible).
6. **Scripts de migration one-shot exposés en GET permanent, non idempotents** : `article/import`, `article/importgratuit`, `agenda/import`, `cote/import`, `rencontre/import`, `BOController::script`, `StatController::setDefaulsStat` (génère de FAUSSES statistiques aléatoires si rejoué — à ne surtout pas exécuter).
7. **CORS** : `Access-Control-Allow-Origin: *` sur toute l'API + headers dupliqués en dur.

## 5. SEO — état actuel (à reconstruire presque entièrement)

- **Pas de balise canonical**, pas d'Open Graph, pas de Twitter Card sur aucune page.
- **Pas de `@nuxtjs/sitemap`** actif côté frontend ; le générateur backend (`SiteMapController@generate`) tourne sans cache à chaque appel et applique des filtres de statut incohérents selon les entités.
- **`server/301.json` ne contient que 5 redirections** — ce n'est pas une table de correspondance ancien→nouveau site exploitable ; l'essentiel de la logique est un middleware Express (`server/redirects.js`) qui gère : normalisation trailing-slash, nettoyage de `?id=` (avec perte du paramètre), canonicalisation de domaine, et un seul pattern spécifique (`agenda.php3?genre=xxx`) résiduel d'une migration antérieure PHP3→Nuxt.
- **Conséquence pour la refonte** : il n'existe pas de mapping ancien/nouveau exhaustif à réutiliser. La stratégie de préservation SEO devra s'appuyer sur l'historique Google Search Console (`t_sc_all_urls`, `gsc_suppression`), les logs serveur si disponibles, et la structure d'URL actuelle du site en production (à crawler/analyser en Phase 2).
- **Système SEO admin existant** (`t_seo_entity`/`t_seo_groupe`) bon socle conceptuel à reprendre avec un vrai fallback dynamique (valeur perso → sinon génération automatique), au lieu du pré-remplissage en masse actuel.
- **Double tracking Analytics legacy** : Universal Analytics (`UA-...`, obsolète/désactivé par Google) + GA4 en parallèle — à nettoyer.
- **Cache Nuxt agressif mal réglé** (TTL ≈ 416 jours) côté ancien serveur — anti-pattern à ne pas reproduire.

## 6. Frontend — navigation, URLs et administration actuelles

- **Navigation réelle** (codée en dur dans `Header.vue`, pas dans `constants/menu.js` qui est un résidu de template inutilisé) : Accueil · Annuaire · Agenda · Annonces · Cinéma · Enfants · Sports · Mariages · Restaurants · Spectacles · La Nuit · Bons Plans · Forum (Rencontres) · Contact. Enfants/Sports/Mariages/Restaurants/Spectacles/La Nuit = vues filtrées de l'annuaire (routes courtes déclarées dans `nuxt.config.js`).
- **Patterns d'URL** : annuaire `/annuaire/:cat?/:scat1?/:scat2?` puis fiche `/fiche/:p1..p6?` (profondeur variable) ; news `/news/:slug` ; cinéma `/film/:slug`, `/cinema/salle/:slug` ; agenda `/agenda/:slug?` avec query `date`/`search`/`genre` ; annonces `/annonces/*` = **iframe vers un WordPress externe** (`annonces.toulouseweb.com`), pas un module natif.
- **Recherche/pagination non unifiées** : scroll infini sur les news, navigation jour/jour + calendrier sur l'agenda, arborescence + recherche plein texte côté client sans pagination serveur sur l'annuaire, aucune pagination publique sur le cinéma.
- **Admin actuelle** : une page Nuxt par entité (`pages/admin/*.vue`), code dupliqué (table + formulaire conditionnel réimplémentés à chaque fois), pas de composant CRUD réutilisable, auth vérifiée à la main dans chaque `mounted()` (pas de middleware Nuxt, pas de protection SSR).
- **Design system** : inexistant. CSS SCSS daté (Verdana 11px, couleurs en dur, balises `<font>`), résidus d'un template dashboard acheté ("Multiply") jamais nettoyés. À refaire intégralement.
- **Fonctionnalités additionnelles à ne pas perdre** : bons plans avec coupons imprimables (popup), proposition d'événement par le public (`agenda_add.vue`), proposition de lieu par le public (`area_add.vue`), page vitrine "Création de sites" (`t_creation_site`/`t_realisation_site`), réseau de portails partenaires (aeromorning.com, toulouse-enfant.com, etc.).

## 7. Décisions d'architecture actées (2026-08-23)

| Décision | Choix retenu |
|---|---|
| Stack technique | **Laravel (dernière version stable) monolithe**, rendu serveur Blade + Alpine.js + Tailwind CSS pour le site public, **Filament** (v3, sur Livewire) pour l'administration |
| Module Rencontres/Toulousains | **Archivé** — export RGPD en lecture seule des données existantes, fonctionnalité non reconstruite dans la refonte, aucune route/table active dans le nouveau schéma |
| Hébergement production | **Mutualisé/cPanel**, pas de process Node permanent, pas de Redis supposé disponible |

Conséquences directes sur l'architecture :
- Pas d'API séparée à maintenir/sécuriser en plus du frontend (élimine la classe de risques IDOR/CORS constatée sur l'ancienne API publique) — les pages publiques et les formulaires (contact, dépôt d'annonce, proposition d'événement) sont des routes Laravel classiques avec CSRF natif.
- Filament fournit nativement : authentification admin sécurisée, gestion des rôles/permissions (via `spatie/laravel-permission`), CRUD généré par "Resource" (remplace `BOController` par des classes PHP typées et validées, plus jamais de table arbitraire exposée), formulaires avec validation, upload de médias.
- Queue = driver `database` (pas de worker permanent requis) : les tâches asynchrones (envoi d'email, traitement d'image) sont traitées via `php artisan queue:work --stop-when-empty` déclenché à chaque minute par le scheduler cPanel — pattern standard en hébergement mutualisé.
- Cache = driver `database` ou `file`. Sessions = driver `database`.
- Recherche = index `FULLTEXT` MySQL/MariaDB natifs (pas de dépendance externe type Meilisearch/Algolia, cohérent avec l'hébergement mutualisé ; réévaluable si migration vers VPS plus tard).
- Un seul point d'entrée cron : `* * * * * php /home/.../artisan schedule:run >> /dev/null 2>&1`, toute la planification (scraping, sitemap, purge, queue) est ensuite définie dans `app/Console/Kernel.php` — voir §13.

## 8. Architecture cible — structure du projet

```
app/
  Models/                 Eloquent (Listing, Category, Event, EventCategory, Cinema, Movie, Screening,
                           Classified, News, Slider, ContactMessage, Redirect, ClickEvent, SeoMeta, ...)
  Filament/
    Resources/            1 classe par entité admin (ListingResource, EventResource, CinemaResource, ...)
    Widgets/              Widgets tableau de bord (stats de clics, dernières annonces à modérer, ...)
  Http/
    Controllers/          Contrôleurs publics, un par domaine (HomeController, ListingController,
                           EventController, CinemaController, ClassifiedController, NewsController,
                           ContactController, ClickTrackingController, SitemapController)
    Middleware/           TrackClickMiddleware (option), RedirectLegacyUrls, ...
  Services/
    Seo/                  SeoResolverService (fallback perso → généré), StructuredDataBuilder (Schema.org)
    Scraping/             EventScraperService, CinemaScraperService (+ un "driver" par source)
    Stats/                ClickTrackingService
    Media/                ImageProcessingService (redimensionnement, WebP, thumbnails via spatie/laravel-medialibrary)
  Console/Commands/       scrape:events, scrape:cinema, sitemap:generate, seo:apply-defaults,
                           classifieds:expire, events:archive-past, redirects:audit
resources/views/
  components/             Design system (card, badge, button, form-*, pagination, breadcrumb, ...)
  layouts/                app.blade.php (public), admin délégué à Filament
  <domaine>/              une vue par type de page (annuaire, agenda, cinema, actualites, annonces, contact)
routes/web.php            groupé par domaine, toutes les routes publiques (pas de routes/api.php séparées)
database/migrations/      schéma cible (voir §9), une migration par table, contraintes FK strictes
database/seeders/         catégories de base, rôles/permissions, données de démo
storage/logs/migration/   logs de la migration legacy → cible (un fichier par domaine, voir §11)
```

Design system : tokens Tailwind (couleurs, typographie, espacements) centralisés dans `tailwind.config.js`, composants Blade réutilisables dans `resources/views/components` (card fiche, card événement, badge statut, bouton, formulaire, breadcrumb, pagination, état vide/chargement) — un seul langage visuel pour tout le site, cohérent avec le §19 du brief.

## 9. Schéma de base de données cible (proposition)

Principes transversaux : clés primaires `id` auto-incrémentées, `legacy_id` (nullable, indexé) sur chaque table migrée pour tracer l'origine et permettre une migration idempotente, `slug` unique par table avec `spatie/laravel-sluggable`, statuts en `enum`/colonne dédiée (jamais de `char(1)` façon T/F/D), soft-deletes (`deleted_at`) sur les entités éditoriales, timestamps partout, médias gérés par `spatie/laravel-medialibrary` (table `media` unique, conversions automatiques thumbnail/WebP) plutôt qu'une colonne image par table.

**Annuaire**
- `categories` — `id, parent_id, type(annuaire), name, slug, level(0-2), icon, description, order, is_active, legacy_id`
- `listings` — `id, title, slug, tier(free|paid), status(draft|pending|published|rejected|archived), description, short_description, address, city, postal_code, lat, lng, phone, email, website, opening_hours(json), social_links(json), reservation_url, click_collect_url, cuisine_type(nullable, spécifique restaurants), published_at, legacy_id`
- `listing_category` (pivot m2m listings↔categories)
- `amenities`, `listing_amenity` (pivot — remplace `t_icone`/`t_encadre_icone`)

**Agenda / Événements**
- `areas` — `id, name, address, city, postal_code, lat, lng, phone, website, legacy_id`
- `event_categories` — `id, name, slug (dont 'theatre'), color, icon, order, legacy_id`
- `events` — `id, area_id(FK→areas, corrige le bug legacy), title, slug, subtitle, description, image, price, start_date, end_date, schedule(json), booking_url, status(draft|pending|published|expired|cancelled), source(manual|scraped|user_submitted), external_ref(nullable, dédup scraper), legacy_id`
- `event_category` (pivot m2m)

**Cinéma**
- `cinemas` (salles), `movies`, `screenings`(FK cinema_id, movie_id), `screening_times`, `screening_types` + pivot, `languages`, `movie_comments` — reprise directe du modèle legacy (bien conçu), assaini (FK strictes, dédup par `external_ref` = identifiant AlloCiné)

**Annonces**
- `classified_categories` (arbre auto-référencé, extensible librement)
- `classifieds` — `id, category_id, user_id(nullable), title, slug, description, price, location, contact_phone, contact_email, status(pending|published|rejected|expired|archived), is_featured, expires_at, moderated_by, moderated_at, rejection_reason`

**Actualités**
- `news_categories`, `news` — `id, category_id, title, slug, excerpt, body, status(draft|pending|published|archived), published_at, author_id`

**Homepage / Sliders**
- `sliders` — `id, title, image, link_url, client_name, order, delay_ms, starts_at, ends_at, is_active, legacy_id`
- `slider_placements` (pivot slider↔page, remplace `t_slider_place`/`t_slider_page`)

**Contact**
- `contact_messages` (remplace `t_contact_us`), `partner_sites`

**SEO / Redirections**
- `seo_meta` — polymorphe (`seoable_type`, `seoable_id`) : `title, description, canonical_url, robots, og_image, structured_data(json)` — fallback géré en code (perso si renseigné, sinon génération automatique par `SeoResolverService`), jamais de pré-remplissage en masse comme l'ancien système
- `redirects` — `id, from_path(unique, indexé), to_path, status_code(301|302), hits_count, is_active` — administrable, alimenté par la migration (voir §11) et par un formulaire admin Filament

**Statistiques de clics (remplace `t_stat_counter`/`t_stat_entites`)**
- `click_events` — `id, entity_type, entity_id, context(nullable), url, referrer, user_agent, ip_hash, session_hash, created_at`, index composite `(entity_type, entity_id, created_at)`. Un seul endpoint `POST /track-click` appelable depuis n'importe quel composant Blade (bannière, carte catégorie, encadré, fiche film...) via un petit helper JS (`data-track="listing:123:card"`), + tracking automatique côté serveur sur les redirections sortantes (clics "site web"/"réserver"). Dashboard Filament : widgets par type d'entité et par période, réutilisant la logique de `StatController` legacy (résumés jour/mois/année) en Eloquent.

**Scraping**
- `scraper_sources` (remplace `t_agenda_scrapping`) — `id, name, type(agenda|cinema), driver_class, config(json), is_active, last_run_at, last_status`
- `scraper_runs` — `id, source_id, started_at, finished_at, status(success|partial|failed), items_found, items_created, items_updated, items_skipped, error_message` — logs exploitables depuis l'admin (§21 du brief)

**Administration / auth**
- `users` (déjà présent dans le schéma neuf, structure Laravel standard) + tables `roles`/`permissions` (`spatie/laravel-permission`)

## 10. Plan de migration `toulouseweb_old` → `toulouseweb`

**Principe** : jamais de `mysqldump | mysql` brut (schéma cible différent). Une connexion secondaire Laravel `legacy` (lecture seule) vers `toulouseweb_old`, une commande Artisan idempotente par domaine, exécutable indépendamment et rejouable sans dupliquer (upsert par `legacy_id`).

**Ordre d'exécution** (respecte les dépendances FK) :
1. `migrate:reference-data` — catégories annuaire, catégories agenda, catégories annonces, catégories news, aires/lieux (`t_areas`), langues/types de projection cinéma
2. `migrate:listings` — `t_article`+`t_art_categ`+`t_category` → `listings`/`categories`/`listing_category`, `t_carousel`/`t_icone`/`t_encadre_icone` → médias/`amenities`
3. `migrate:events` — `t_agendas`+`t_agenda_cat`+`t_agenda_categories` → `events`/`event_categories`/`event_category`, correction de la FK `id_area` au passage
4. `migrate:cinema` — `t_cine*`, `t_cine_film`, `t_cine_projection`(+heures/+types, fenêtre de dates valides uniquement, jamais les tables `_bkp`)
5. `migrate:news` — `t_news`+`t_news_cat` → `news`/`news_categories`, statuts `D/F/S/T` → enum propre
6. `migrate:sliders` — `t_sliders`+`t_slider_place`+`t_slider_page`
7. `migrate:contacts` — `t_contact_us` (actif) uniquement ; `t_contacts`/`annuaire` archivés en export CSV hors base, non migrés
8. `migrate:seo` — `t_seo_entity`/`t_seo_groupe`/`t_entete` → `seo_meta` polymorphe (résolution du type d'entité cible par groupe)
9. `migrate:redirects` — construction de `redirects` à partir de `t_sitemap`/`t_sc_all_urls`/`gsc_suppression` et des patterns d'URL legacy identifiés (annuaire, fiches, agenda pré-Nuxt) vers les nouvelles URLs générées à l'étape 2-3
10. `migrate:click-stats` — `t_stat_counter` (2,78M lignes) → `click_events`, en dernier, par lots de ~5000, uniquement les `id_stat_entite` réellement utilisés (rubrique/encadré/agenda/accueil/news/sliders)

**Chaque commande** : lecture chunked (`DB::connection('legacy')->table(...)->orderBy('id')->chunk(500, ...)`), nettoyage (encodage mojibake, dates invalides → `null`, lignes orphelines → loggées et ignorées, jamais insérées), transformation (mapping de champs, normalisation des statuts), écriture idempotente (`updateOrCreate(['legacy_id' => ...], [...])`), log détaillé dans `storage/logs/migration/<domaine>.log` (nombre traité/créé/mis à jour/ignoré + raison).

**Post-migration** : script de vérification d'intégrité (comparaison des volumes attendus vs migrés par domaine, contrôle des FK, échantillon de contrôle manuel sur 20 fiches/événements/films).

**RGPD** : le module Rencontres n'est pas migré vers le nouveau schéma applicatif — un export CSV/SQL des tables concernées (`t_toulousains`, `t_courriers`, `t_favoris`, `t_cote_d_amour`) est réalisé à la demande pour archivage hors ligne, hors de toute base connectée à l'application.

## 11. Cron / commandes

Un seul cron serveur, à configurer en production : `* * * * * php artisan schedule:run`. Toutes les tâches sont déclarées dans `routes/console.php` (Laravel 12 — pas de `Console/Kernel.php`) :

| Commande | Rôle | Fréquence | Statut |
|---|---|---|---|
| `queue:work --stop-when-empty` | Traite la file (emails, images) | Chaque minute | ✅ Implémenté |
| `sitemap:generate` | Régénère `sitemap.xml` en fichier statique caché | Quotidien | ✅ Implémenté (§13) |
| `scrape:cinema` | Fiches film + association salle depuis les sources actives (`scraper_sources`, type `cinema`) | Quotidien à 5h | ✅ Implémenté (§13 — voir détail ci-dessous) |
| `scrape:events` | Scraping agenda (sources dans `scraper_sources`, type `agenda`) | Toutes les 3-6h | ❌ Pas encore écrit |
| `events:archive-past` | Statut `expired` sur événements passés | Quotidien | ❌ Pas encore écrit |
| `classifieds:expire` | Statut `expired` sur annonces dépassant leur durée de publication | Quotidien | ❌ Pas encore écrit |
| `redirects:audit` | Repère les 404 fréquentes sans redirection associée | Hebdomadaire | ✅ Implémenté (§13 — voir détail ci-dessous) |

**Commandes de récupération ponctuelle (pas de cron, à rejouer manuellement contre une source de données)** : `migrate:partner-sites` et les 7 commandes `images:{movies,news,listings,amenities,partner-sites,events,sliders}` — voir "Import des images" en §13 pour le détail, les volumes réels et la justification de ne pas committer les fichiers importés dans git.

### `scrape:cinema` — détail (brief §7/§9/§21)

**⚠️ Correction d'audit (2026-08-24)** : la première version de cette section décrivait un scraper `PatheGaumontDriver` réécrivant `CinemaController::autoUpdateCinema`. Le client a signalé que ce n'était pas le mécanisme réel, et vérification faite dans `old/backEnd/routes/api.php` : **`autoUpdateCinema` n'est référencée par aucune route** — c'est du code mort, jamais appelé par un cron en production. Le vrai mécanisme (confirmé par le client : cron `wget .../api/autoUpdateCinemaAllocine/{id}` par salle, `{id}` = `t_cine.id`, et par la lecture complète du contrôleur) scrape l'**API JSON interne d'AlloCiné**, salle par salle. `PatheGaumontDriver` a été supprimé et remplacé par `AllocineDriver`.

- **Commande** : `php artisan scrape:cinema` (option `--source=<id>` pour ne relancer qu'une source précise) — inchangée, agnostique du driver.
- **Rôle** : réécrit `CinemaController::autoUpdateCinemaAllocine` + `autoUpdateCinemaAllocineLiens`/`Liens2` (legacy réel, `old/backEnd/.../CinemaController.php` lignes ~1214-1745). Découvre/actualise les fiches film (titre, réalisateur, casting, genres, synopsis, affiche, année) et crée/met à jour l'association salle+film+langue (`screenings`) ainsi que les **horaires précis** (`screening_times` : jour de semaine, heure, lien de réservation) — contrairement au scraper Pathé-Gaumont (dead-code) initialement documenté, **ce mécanisme scrape bien des horaires réels**.
- **Source de données** : `App\Services\Scraping\Cinema\AllocineDriver`, une source par salle active (`scraper_sources`, seedées via `ScraperSourcesSeeder` — 24 salles sur 27 lignes `t_cine`, celles ayant une URL AlloCiné exploitable). Endpoint : `GET https://www.allocine.fr/_/showtimes/theater-{id}/d-{Y-m-d}/` où `{id}` est l'identifiant de salle AlloCiné extrait de `t_cine.url` (`salle_gen_csalle=P0057.html` → `P0057`), interrogé une fois par jour de la fenêtre (`window_days`, 7 par défaut).
- **Pathé-Gaumont Wilson n'a pas de traitement spécifique** : son `t_cine.url` est aussi une URL AlloCiné (`P0057`) — elle suit exactement le même mécanisme que les 23 autres salles.
- **⚠️ Non re-vérifié en direct** : le sandbox de développement n'a pas d'accès sortant vers `allocine.fr`. Les noms de champs (`results[].movie.{internalId,title,genres[].translate,synopsis,poster.url,credits[].person,cast.edges[].node,data.productionYear}`, `results[].showtimes.*[].{diffusionVersion,startsAt,data.ticketing[].{provider,urls}}`) proviennent de la lecture directe du code source legacy réel, pas d'une réponse observée. **À valider dès la première exécution en environnement avec accès réseau sortant** (voir `scraper_runs` dans l'admin).
- **Corrections/simplifications vs. legacy** (voir docblock complet de `AllocineDriver`) :
  - **Fenêtre de programmation stable** : le legacy recalculait la borne basse de la semaine sur la date d'exécution du cron (bug : la variable `$dateMercredi` valait en réalité "aujourd'hui", pas le mercredi de la semaine), créant une nouvelle ligne `screenings` chaque jour au lieu de mettre à jour la même semaine. Ici, la fenêtre [mercredi de la semaine en cours → mardi suivant] est calculée une seule fois par exécution, peu importe le jour où le cron tourne.
  - **Fusion des 3 endpoints legacy en un seul passage idempotent** : le legacy avait besoin d'un second mécanisme (`autoUpdateCinemaAllocineLiens` + `Liens2`, table de staging `t_scrapping_tmp`, traitement par lots de 50) uniquement parce que sa fonction principale n'insérait le lien de réservation qu'à la création d'un horaire, jamais en mise à jour. Ici, chaque passage réécrit systématiquement `booking_url`, staging superflu.
  - Dédoublonnage des films par identifiant AlloCiné (`internalId` → `movies.external_ref`) plutôt que par slug recalculé sur le titre. Un repli best-effort (`Str::slug($titre)`) rattache les ~17 300 films déjà migrés depuis `t_cine_film` (qui n'ont pas cet identifiant) au lieu d'en créer des doublons, et rétro-remplit `external_ref` — non garanti à 100 %, à surveiller après le premier run réel.
  - Pas de pagination (`p-{n}`) : le contrôleur legacy actif n'en faisait pas non plus pour cette route — limite héritée, pas une régression.
  - Ne reproduit pas le lien "bande-annonce" du legacy (bug de copier-coller constaté : identifiant `cmedia` codé en dur, identique pour tous les films). Le vrai scraping de bande-annonce (`autoUpdateCinemaAllocineBA`, HTML) est une fonctionnalité distincte non reprise dans cette phase.
- **Bug Eloquent corrigé au passage** : `Screening::$casts` utilisait `'date'` sans format explicite — un `updateOrCreate` matchant sur une chaîne `'Y-m-d'` nue échouait alors silencieusement à retrouver une ligne déjà stockée en `'Y-m-d H:i:s'`, créant un doublon (constaté sur SQLite dans les tests ; MySQL tronque silencieusement une colonne `DATE`, donc invisible en production, mais le fix — cast `'date:Y-m-d'` — est correct indépendamment du moteur).
- **Logs/erreurs** : chaque exécution crée une ligne `scraper_runs` (found/created/updated/skipped, statut success/partial/failed, message d'erreur) consultable dans l'admin. Un échec sur une source n'interrompt pas les autres sources actives.
- **Tests** : `tests/Feature/ScrapeCinemaTest.php` (création, dédoublonnage par slug pour un film déjà migré, ré-exécution idempotente avec rafraîchissement du lien de réservation, entrée sans film ignorée, échec amont géré proprement, source inactive non exécutée).

### Relation manager "Séances" (`CinemaResource`)

Complément manuel au scraper : `App\Filament\Resources\CinemaResource\RelationManagers\ScreeningsRelationManager` (onglet "Séances" sur la fiche d'une salle) liste les `screenings` de la salle (film, langue, fenêtre de programmation, types de projection, avant-première/coup de cœur) et permet de créer/éditer/supprimer une séance — avec ses horaires (`screening_times`, jour de semaine 0-6 + heure + lien de réservation) saisis **inline** via un `Repeater` lié par relation (`->relationship('times')`), pas de navigation séparée. Utile pour les 3 salles non couvertes par `scrape:cinema` (inactives dans le legacy, pas d'URL AlloCiné) et pour corriger ponctuellement une séance scrapée. Tests : `tests/Feature/CinemaScreeningsRelationManagerTest.php` (page d'édition sans erreur fatale ; création réelle d'une séance + d'un horaire imbriqué via `Livewire::test()->mountTableAction()`, vérifiée en base).

### `redirects:audit` — détail (brief §15)

Repère les 404 fréquentes sans redirection associée. Nécessitait d'abord de **journaliser** les 404 réelles — jusque-là rien ne gardait trace de ce qui échouait, seulement de ce qui redirigeait avec succès (`redirects.hits_count`) :

- Nouvelle table `missed_redirects` (`path` unique, `hits_count`, `first_seen_at`, `last_seen_at`) — alimentée par `MissedRedirect::record($path)`, appelé depuis `Controller::redirectOrAbort()` (tous les contrôleurs de contenu) et `RedirectFallbackController`, juste avant le 404 final. `updateOrCreate`-like : incrémente si le chemin est déjà connu, ne duplique jamais.
- `php artisan redirects:audit` (`--min-hits=3` par défaut, `--limit=25`) : tableau trié par fréquence décroissante. Volontairement une simple liste, pas d'automatisation — décider qu'une 404 mérite une redirection (et vers où) reste un jugement humain.
- `MissedRedirectResource` (admin, groupe "SEO & Technique") : même donnée en continu dans l'admin (pas seulement au moment du cron), lecture seule + action "Écarter" (supprime une ligne non pertinente, ex. bruit de scanner) — pas de création/édition, cette liste n'est jamais saisie à la main.
- **Bug MySQL trouvé et corrigé avant tout commit** : la migration initiale déclarait `first_seen_at`/`last_seen_at` en `timestamp` NOT NULL sans défaut — MySQL en mode strict refuse deux colonnes timestamp NOT NULL sans valeur par défaut sur une même table (`SQLSTATE[42000]: ... Invalid default value`). Invisible sur SQLite (tests), révélé uniquement en migrant contre la vraie base MySQL locale. Corrigé en rendant les deux colonnes nullable (toujours renseignées en pratique par `MissedRedirect::record()`).
- Tests : `tests/Feature/RedirectsTest.php` (chemin inconnu journalisé et incrémenté sur répétition, redirection connue jamais journalisée comme manquée, commande filtrée par seuil).
- Vérifié en HTTP réel (`php artisan serve` + `curl`) : deux 404 de nature différente (résolution manuelle via `redirectOrAbort` et fallback générique) correctement journalisées et incrémentées en base MySQL réelle.

Documentation complète des futures commandes (`scrape:events`, `events:archive-past`, `classifieds:expire`) à produire au moment de leur implémentation, dans ce même tableau.

## 12. Plan de développement par phases (mise à jour post-décisions)

| Phase | Contenu | Statut |
|---|---|---|
| 1. Audit | Analyse complète ancien site + base — voir §1-6 | ✅ Terminé |
| 2. Architecture & BDD | Stack, schéma cible, plan de migration — voir §7-11 | ✅ Terminé |
| 3. Design system & layout | Scaffold Laravel + Filament, Tailwind config, composants Blade de base, layout public | 🔜 Prochaine étape |
| 4. Administration | Resources Filament par entité, rôles/permissions | À venir |
| 5. Migration des données | Exécution des commandes `migrate:*` sur environnement local | À venir |
| 6. Annuaire | Listings, catégories, recherche, fiches payantes/gratuites, dépôt public | ✅ Fait (§13) — reste : recherche géographique |
| 7. Agenda / événements / théâtre | Listing, filtres, calendrier, scraping événements | 🟡 Fait sauf scraping (§13) — décision produit en attente |
| 8. Cinéma | Modèle, scraping AlloCiné réécrit (une source par salle), UI | ✅ Fait (§13) — reste : vérification en direct dès accès réseau disponible |
| 9. Annonces | Dépôt public, modération admin, catégories dynamiques | À venir |
| 10. Homepage | Slider admin, sections dynamiques | À venir |
| 11. SEO/GEO, URLs, redirections | `seo_meta`, sitemap, redirections, structured data | À venir |
| 12. Performance & sécurité | Cache, index, audit sécurité complet | À venir |
| 13. Tests | Tests fonctionnels critiques (modération, migration, SEO) | À venir |
| 14. Déploiement | Procédure cPanel, cron, checklist mise en prod | À venir |

Ce tableau est mis à jour à la fin de chaque phase.

## 13. État réel de l'implémentation (mis à jour au fil du code)

Cette section reflète ce qui existe vraiment dans le dépôt, pas seulement ce qui est planifié — à consulter en premier avant de reprendre le travail (voir README.md §Mémoire du projet).

### Scaffold (Phase 3, partiel)

- Projet Laravel 12 (PHP 8.2) à la racine du dépôt (à côté de `old/`, conservé intact). `.env` pointe sur `toulouseweb` (MySQL local), plus une connexion secondaire `legacy` (config/database.php) vers `toulouseweb_old` en lecture seule pour la migration.
- Session/queue/cache en driver `database` (cohérent avec la décision hébergement mutualisé, §7).
- Packages installés : `filament/filament` (admin), `filament/spatie-laravel-media-library-plugin`, `spatie/laravel-permission`, `spatie/laravel-medialibrary`, `spatie/laravel-sluggable`, `spatie/laravel-sitemap`. Tailwind 4 + Vite déjà fournis par le scaffold Laravel par défaut — thème/tokens visuels du design system (§19 du brief) pas encore personnalisés.
- Panel Filament installé sur `/admin`. Accès restreint aux utilisateurs ayant un rôle (`User::canAccessPanel`), rôles créés par `database/seeders/RolesAndAdminSeeder.php` (`super_admin`, `admin`, `editor`, `moderator`) avec un compte super-admin initial (email du propriétaire du projet, mot de passe temporaire `ChangeMe!ToulouseWeb2026` — **à changer dès la première connexion**).

### Base de données cible (Phase 2 → implémentée)

Toutes les migrations du schéma cible décrit en §9 sont écrites et appliquées sur `toulouseweb` (fichiers `database/migrations/2026_08_23_140*.php`, regroupées par domaine : annuaire, agenda, cinéma, annonces, actualités, homepage/contact, SEO/redirections, click_events, scraping). Tables `permissions`/`model_has_roles`/etc. (Spatie) et `media` (Spatie Media Library) également en place.

### Modèles Eloquent

Tous les modèles du schéma cible existent dans `app/Models/` avec leurs relations, `HasSlug` (spatie/laravel-sluggable), `SoftDeletes` où pertinent, et deux traits transversaux dans `app/Models/Concerns/` :
- `HasSeoMeta` : relation `morphOne` vers `SeoMeta` + `resolveSeo()` déléguant à `App\Services\Seo\SeoResolverService`.
- `Trackable` : relation `morphMany` vers `ClickEvent`, utilisée par le futur affichage des stats par entité.

`Category::booted()` dérive automatiquement `level` depuis le parent (remplace la logique SQL brute `getChildNiveauByParent` du `CategoryController` legacy).

### Services transversaux

- `App\Services\Seo\SeoResolverService` : résout title/description/canonical/robots/og_image/structured_data d'une entité — valeur admin (`seo_meta`) si renseignée, sinon génération automatique à partir des attributs du modèle (implémente le principe "SEO personnalisé -> sinon génération automatique" du brief §13). **Pas encore branché sur les vues publiques** (aucune vue publique n'existe encore, Phase 6+).
- `App\Services\Stats\ClickTrackingService` + `App\Http\Controllers\ClickTrackingController` : endpoint unique `POST /track-click` (throttlé 60/min), enregistre n'importe quel clic (`entity_type`, `entity_id`, `context`, referrer, user-agent, IP hashée). Répond à la demande explicite du client de statistiques étendues à chaque clic du site. Câblé (soit en `data-track="type:id:contexte"` direct, soit via le prop `:track=` de `x-ui.card`, voir `resources/js/track-click.js`) sur : `listing`, `event`, `movie`, `news`, `classified`, `category`, `partner_site`, `slider` — couvre en pratique toutes les entités de contenu public. **Correction** : une première rédaction de cette section affirmait à tort que les actualités n'étaient pas suivies — vérification faite avec `grep -o ':track="'` (et pas seulement `data-track="` en dur), `news` est bien tracké sur `actualites/index`, `actualites/show` et les cartes homepage.

### Administration Filament (Phase 4, amorcée)

21 ressources créées sous `app/Filament/Resources/`, groupées par domaine dans la navigation (Annuaire, Agenda, Cinéma, Annonces, Actualités, Accueil, Contact & Contenu, SEO & Technique, Réglages) :

| Ressource | Particularités déjà codées |
|---|---|
| `CategoryResource` | Niveau auto-dérivé, slug auto-généré depuis le nom |
| `ListingResource` | Distinction visuelle fiches gratuites/payantes, section contenu enrichi conditionnelle (`tier === 'paid'`), upload logo/galerie via Spatie Media Library, catégories/équipements en relation many-to-many, bloc SEO imbriqué (`->relationship('seoMeta')`) |
| `EventResource` | Catégories multiples (dont "theatre"), statuts avec badges colorés, filtre par catégorie/statut |
| `EventCategoryResource` | Sélecteur de couleur (`ColorPicker`) |
| `NewsResource` | Éditeur riche (`RichEditor`), action rapide "Publier" pour les news en attente |
| `ClassifiedResource` | **Workflow de modération strict implémenté** : actions "Valider"/"Refuser" dédiées (jamais d'édition directe du statut en masse), badge de compteur d'annonces en attente dans la sidebar |
| `SliderResource` | Gestion des emplacements (`slider_placements`) via `CheckboxList` avec sauvegarde relationnelle custom |
| `RedirectResource` | Type de redirection 301/302 en select, compteur de hits en lecture seule |
| `CinemaResource`, `MovieResource`, `ClassifiedCategoryResource`, `NewsCategoryResource`, `AreaResource`, `AmenityResource`, `ContactMessageResource`, `PartnerSiteResource`, `ScraperSourceResource` | Générées avec `--generate` (formulaires/tables auto-inférés du schéma), pas encore personnalisées en profondeur |
| `MissedRedirectResource` | Lecture seule + action "Écarter" (jamais de création/édition manuelle, voir §11) |
| `UserResource` | Rôles en relation many-to-many (`Select` multiple, au moins un requis), mot de passe haché à la volée (`dehydrateStateUsing`), non déhydraté si laissé vide en édition, suppression de son propre compte masquée |
| `RoleResource` | Nom de rôle seul (pas de permissions granulaires, voir docblock) |

**Fait depuis** : relation manager Séances sur `CinemaResource` (Phase 8) ; page "Paramètres du site" (SEO/Analytics globaux inclus, voir ci-dessous) ; `MissedRedirectResource` (§11) ; gestion utilisateurs/rôles (`UserResource`/`RoleResource`, voir ci-dessous).

**Reste à faire côté admin** : rien d'identifié pour l'instant au-delà de ce qui est déjà listé ailleurs (dashboard SEO/redirections avancé, granularité de permissions si un besoin se présente).

### Gestion utilisateurs/rôles (brief §12/§18)

Jusqu'ici seulement possible via `RolesAndAdminSeeder`/tinker, aucune interface. `UserResource` et `RoleResource` (groupe "Réglages") comblent ce manque :

- `UserResource` : nom, email, mot de passe (haché, optionnel en édition — laisser vide ne le modifie pas), rôles (`Select` multiple lié par relation, **au moins un requis**). Suppression de son propre compte masquée dans la liste ET sur la fiche (évite un verrouillage accidentel hors de l'admin).
- `RoleResource` : nom de rôle seul — ce projet n'a pas de permissions granulaires (un rôle donne simplement accès au panel, sans distinction de capacités entre rôles). À enrichir avec de vraies permissions Spatie si un besoin de granularité apparaît (ex. "moderator" limité aux annonces/commentaires) — décision produit à prendre le moment venu.
- **Correction apportée à `User::canAccessPanel()`** : vérifiait auparavant une liste figée de 4 noms de rôle (`super_admin`, `admin`, `editor`, `moderator`). Depuis que les rôles sont administrables, cette liste en dur serait devenue un piège silencieux : créer un nouveau rôle depuis l'admin et l'assigner à un utilisateur n'aurait donné accès à rien tant que le code n'aurait pas aussi été mis à jour. Remplacé par une vérification générique (`$this->roles()->exists()`) — n'importe quel rôle donne désormais accès.
- Tests : `tests/Feature/UserRoleManagementTest.php` — accès panel refusé sans rôle, accès accordé avec un rôle **custom** (pas dans l'ancienne liste figée, pour verrouiller la régression), création d'utilisateur avec mot de passe haché, création sans rôle rejetée, suppression de son propre compte impossible, création d'un nouveau rôle. **Piège rencontré en testant** : un `Select` lié par `relationship()` attend la CLÉ du modèle en état de formulaire (l'id du rôle), pas son nom affiché — un premier jet du test passait `'roles' => ['editor']` (le nom) et provoquait une vraie `QueryException` (FK invalide), corrigé en passant `$role->id`.

### Dashboard admin — statistiques de clics (brief : "chaque clic... doit être ajouté dans cette statistique")

3 widgets Filament (`app/Filament/Widgets/`, auto-découverts via `discoverWidgets()` dans `AdminPanelProvider`, affichés sur le tableau de bord par défaut) :

- `ClicksOverview` (`StatsOverviewWidget`) : total de clics aujourd'hui / 7 jours / 30 jours, toutes entités confondues.
- `ClicksByTypeChart` (`BarChartWidget`) : répartition des clics par type d'entité sur 30 jours.
- `TopClickedEntities` (widget custom, pas `TableWidget`) : top 10 des entités les plus cliquées tous types confondus, avec libellé humain résolu (`App\Services\Stats\EntityLabelResolver`) — widget custom car la requête agrégée (`GROUP BY entity_type, entity_id`) ne correspond à aucun modèle Eloquent unique exploitable par le composant Table de Filament.

`App\Services\Stats\ClickTrackingService` complété avec `totalCount()`, `totalsByType()`, `topEntities()`. `App\Services\Stats\EntityLabelResolver` fait correspondre chaque `entity_type` (chaîne libre côté frontend) à un modèle + colonne d'affichage — **à tenir à jour à chaque nouveau type de clic suivi**.

**Bug trouvé et corrigé en construisant ce dashboard** (avant même un premier commit, pas en production) :
- Le lien de case de calendrier agenda (Phase 7 précédente) portait `data-track="agenda_calendar_day:{date}:..."` — mais `entity_id` DOIT être un entier (`ClickTrackingController` valide `'entity_id' => ['required', 'integer']`, et `track-click.js` fait `Number(entityId)`) ; une date ("2026-08-19") donne `NaN` → `null` en JSON → rejeté par la validation. Échec silencieux (l'appel `fetch`/`sendBeacon` avale l'erreur), jamais remonté à l'utilisateur. Retiré : une case de calendrier n'est pas une entité au sens du brief, contrairement à une fiche/un film/une bannière.
- `EntityLabelResolver` appelait `Model::withTrashed()->find()` uniformément, mais seuls `Listing`/`Event`/`Classified` utilisent `SoftDeletes` — `Movie`/`Category`/`PartnerSite`/`Slider` n'ont pas cette méthode (`BadMethodCallException` si jamais atteint). Corrigé en repli sur `find()` simple partout.
- **Piège Filament découvert en testant** : les widgets sont **lazy par défaut** (`Filament\Support\Concerns\CanBeLazy`, `$isLazy = true`) — leur contenu réel n'est rendu qu'après un aller-retour Livewire déclenché par un observateur d'intersection JS côté navigateur. Un test HTTP serveur (`$this->get('/admin')`, pas de JS) ne voit donc que des widgets vides (`"data":[]`) au premier chargement. Désactivé (`$isLazy = false`) sur les 3 widgets pour un affichage immédiat, à la fois pour l'utilisateur (pas d'attente perceptible sur un dashboard qui n'a rien de coûteux à charger) et pour la testabilité.

Tests : `tests/Feature/AdminDashboardStatsTest.php` (rendu avec de vrais clics enregistrés, rendu sans clic sans erreur).

### Paramètres du site (`Filament\Pages\SiteSettings`, brief §13)

Remplace les valeurs codées en dur dans `components/layouts/app.blade.php` (nom du site, description, image OG par défaut) et le pied de page — **aucune table legacy équivalente** (`t_entete` est un système différent : des overrides SEO par page d'annuaire, déjà couvert par `seo_meta` — pas des réglages globaux), fonctionnalité entièrement nouvelle.

- `App\Models\SiteSetting` : une seule ligne (singleton, `SiteSetting::current()` la crée si absente avec des valeurs par défaut cohérentes avec l'existant) — `site_name`, `tagline`, `description`, `logo`, `default_og_image`, `email`, `phone`, `address`, 5 champs de réseaux sociaux (`socialLinks()` retourne les non-vides, pour le `sameAs` du JSON-LD), `google_analytics_id`, `google_site_verification`.
- `App\Filament\Pages\SiteSettings` : page Filament simple (pas un Resource — rien à lister), formulaire en 4 sections (Identité, Coordonnées, Réseaux sociaux, **SEO & Analytics**), upload logo/image OG via `FileUpload`.
- Consommé par `components/layouts/app.blade.php` (title/description par défaut, meta OG, JSON-LD Organization avec `name`/`logo`/`description`/`sameAs`, balise `google-site-verification` et script `gtag.js` **seulement si renseignés** — pas de script chargé quand aucun identifiant Analytics n'est configuré), `components/site/header.blade.php` (logo si renseigné, sinon repli visuel identique à avant) et `components/site/footer.blade.php` (nom, description, liens sociaux, copyright).
- **"Page SEO globale" (brief, reste à faire historique)** : le title/description/canonical/OG par ENTITÉ était déjà couvert (`seo_meta` + `SeoResolverService`, y compris la homepage via `Page::where('key','home')`). Ce qui manquait réellement était les réglages qui n'appartiennent à AUCUNE entité — `google_analytics_id`/`google_site_verification` ci-dessus. Au passage, `SeoResolverService::generateTitle()`/`generateDescription()` (repli automatique quand aucun `seo_meta` n'est renseigné) avaient "ToulouseWeb" codé en dur — remplacé par `SiteSetting::current()->site_name`, cohérent avec le reste du module. `public/robots.txt` reste un fichier statique non administrable — non traité ici (hors périmètre demandé), à reconsidérer si un besoin réel se présente.
- **Bug préexistant découvert et corrigé au passage** : la clé JSON-LD `'@context'` écrite non échappée dans le Blade était interprétée par le compilateur comme la directive `@context` (façade `Context`, Laravel 11+), corrompant tout le JSON-LD Organization en production silencieusement (jamais détecté avant faute d'avoir vérifié le HTML réellement rendu, pas seulement `assertOk()`). Corrigé en échappant `'@@context'`. À surveiller : tout futur JSON-LD écrit directement en Blade doit faire attention à ce piège.
- Tests : `tests/Feature/SiteSettingsTest.php` (accès admin uniquement, sauvegarde réelle via `Livewire::test()->fillForm()->call('save')`, vérification que la homepage reflète bien des valeurs personnalisées dans le JSON-LD rendu, et que les balises Analytics/vérification n'apparaissent QUE lorsqu'elles sont configurées).

### Tests

`tests/Feature/AdminPanelSmokeTest.php` : vérifie qu'un invité est bien redirigé, qu'un utilisateur sans rôle est bloqué (403), et que l'index + le formulaire de création de chaque ressource sensible se rendent sans erreur serveur. Sert de garde-fou de non-régression pendant la suite du développement — à lancer via `php artisan test` avant chaque commit important (tourne sur SQLite en mémoire, n'impacte jamais la base `toulouseweb`).

### Design system & Homepage (Phase 3 / Phase 10, amorcées)

- **Tokens visuels** (`resources/css/app.css`, Tailwind 4 CSS-first) : palette `brand` (terracotta/brique, couleur de la "Ville Rose"), `ink` (bleu-gris profond pour texte/nav), `accent` (doré, mises en avant ponctuelles) ; typographies auto-hébergées via `@fontsource-variable` (`Outfit Variable` pour les titres, `InterVariable` pour le corps — pas de dépendance à Google Fonts).
- **Composants Blade réutilisables** (`resources/views/components/`) : `layouts.app` (layout public avec meta SEO/OG/Twitter Card/JSON-LD Organization résolus dynamiquement), `site.header` (nav responsive + menu annuaire en dropdown, Alpine.js), `site.footer` (liens + portails partenaires), `site.hero-slider` (carrousel administrable, autoplay/pause au survol, indicateurs), `ui.button`, `ui.badge`, `ui.card`, `ui.section-heading` — un seul langage visuel partagé par toutes les pages à venir (répond au brief §19).
- **Alpine.js** (`resources/js/app.js`) pour l'interactivité légère (menu mobile, dropdown, slider) sans framework JS lourd — cohérent avec la priorité performance/SEO (rendu serveur Blade).
- **Tracking de clics câblé** (`resources/js/track-click.js`) : tout élément avec `data-track="type:id:contexte"` envoie un événement à `/track-click` via `navigator.sendBeacon` (non bloquant). Déjà posé sur les cartes homepage (actus, agenda, cinéma, annuaire, annonces, sliders, portails partenaires) — modèle à réutiliser sur toutes les pages futures.
- **`HomeController`** assemble la homepage à partir des vrais modèles (slider actif sur `home`, dernières actus/événements à venir/films/fiches payantes/annonces publiées, catégories de niveau 0) — remplace la maquette 3-colonnes du legacy par une page en sections empilées orientées découverte (brief §4).
- **`Page` model** (+ `PageResource` Filament) : porte le SEO des pages statiques (accueil, contact, pages vitrine...) via `seoMeta` — nécessaire pour que l'accueil ait un title/description administrables.
- **Bug corrigé pendant l'implémentation** : `SeoMeta` n'avait pas de `$table` explicite — Eloquent cherchait `seo_metas` (pluriel auto) alors que la migration crée `seo_meta` (singulier). Sans ce correctif, **toute** résolution SEO (Listing, Event, Category, News, Cinema, Movie, Page...) aurait échoué en production. Toujours vérifier ce genre de désaccord singulier/pluriel après une migration écrite à la main.
- **`DemoContentSeeder`** (`database/seeders/DemoContentSeeder.php`) : jeu de données fictif (restaurants, événements, films, actus, annonces, slider, partenaires) pour valider visuellement la homepage/admin en local. **Ne jamais exécuter en production** — à vider avant la vraie migration (Phase 5).
- Tests : `tests/Feature/HomepageTest.php` (rendu à vide + avec contenu, toutes sections).

### Migration des données (Phase 5)

10 commandes Artisan sous `app/Console/Commands/Migration/`, exécutées dans l'ordre contre la vraie base `toulouseweb_old` (connexion `legacy`), idempotentes (rejouables sans dupliquer, via `legacy_id`/`legacy_code`) et journalisées dans `storage/logs/migration/<domaine>.log` :

| Commande | Domaine | Table(s) source | Notes |
|---|---|---|---|
| `migrate:reference-data` | Catégories, lieux, équipements, catégories agenda/annonces/actus, langues/types cinéma | `t_category`, `t_areas`, `t_icone`, `t_agenda_categories`, `t_cine_lang`, `t_cine_type_projection`, `t_annonce_category`, `t_news_cat` | Doit tourner en premier (toutes les autres en dépendent) |
| `migrate:listings` | Fiches annuaire | `t_article`, `t_art_categ`, `t_encadre_icone`, `t_carousel` | Images de galerie **non transférées** (fichiers absents du dépôt, voir §16 du brief) |
| `migrate:events` | Agenda | `t_agendas`, `t_agenda_cat` | Corrige le bug FK legacy `id_area` ; statut dérivé du flag ET de la date réelle |
| `migrate:cinema` | Salles, films, séances, horaires, commentaires | `t_cine`, `t_cine_film`, `t_cine_projection`, `t_cine_proj_heures`, `t_cine_proj_types`, `t_cine_comment` | Voir correction de schéma ci-dessous (horaires hebdomadaires récurrents) |
| `migrate:news` | Actualités + commentaires | `t_news`, `t_news_comment` | Catégorie résolue via `legacy_code` (le legacy utilise des codes texte, pas des IDs numériques) |
| `migrate:sliders` | Sliders homepage/pages | `t_sliders`, `t_slider_place`, `t_slider_page` | |
| `migrate:contacts` | Messages de contact actifs | `t_contact_us` | `t_contacts` (2001-2014, obsolète) volontairement exclu, voir audit §4 |
| `migrate:seo` | Métadonnées SEO par entité | `t_seo_entity`, `t_seo_groupe` | Voir mapping des 8 groupes dans le code ; les blocs `ext_*`/`se_h1..h6` (remplissage SEO 2010-2015) ne sont pas repris |
| `migrate:redirects` | Amorce des redirections 301 | `slug_old`/`slug` de `t_article`/`t_news`/`t_cine_film`/`t_category` | Portée volontairement limitée — audit complet des URLs à faire en Phase 11 (voir avertissement affiché par la commande) |
| `migrate:click-stats --truncate` | Historique de clics | `t_stat_counter` (~2,78M lignes) | Insertion brute par lots de 1000, query log désactivé — la seule commande qui nécessite `--truncate` pour être rejouée |

**Corrections de schéma découvertes en migrant les vraies données** (au-delà de la conception initiale, §9) :
- **Cinéma — modèle horaire corrigé** : le legacy ne stocke pas des séances à date fixe mais un gabarit hebdomadaire récurrent. `t_cine_projection` porte une fenêtre de validité (`start_date`/`end_date`, absente du schéma initial — ajoutée sur `screenings`) et `t_cine_proj_heures.jour` est un **index de jour de semaine (0-6)**, pas une date calendaire (`screening_times.day` renommé `weekday`, cast date retiré). La contrainte unique `(cinema_id, movie_id, language_id)` posée initialement était trop stricte (le legacy a plusieurs projections pour un même triplet, distinguées par leur fenêtre de validité) — remplacée par un index simple.
- **Largeurs de colonnes** élargies après rejet SQL par la vraie donnée : `areas.phone`/`listings.phone`/`contact_messages.phone`/`classifieds.contact_phone` (30→255, certains champs "téléphone" legacy contiennent du texte libre), `areas.address`/`listings.address` (255→500), `listings.reservation_url`/`click_collect_url` (255→500), `event_categories.color` (20→50, valeurs `rgba(...)`), `events.booking_url` et `screening_times.booking_url` (255→500/1000, URLs de billetterie avec paramètres UTM longs).
- **Coordonnées GPS invalides** : une salle de cinéma avait une longitude corrompue (`3492220`) — filtrée et mise à `null` avec avertissement au lieu de planter l'import (`MigrateCinema::validCoordinate()`).
- **Bug évité** : `migrate:seo` aurait initialement créé une page "accueil" en double (collision avec la page `home` déjà migrée) — corrigé pour réutiliser la page existante par slug avant d'en créer une nouvelle.

**Bonne surprise sur l'encodage** : contrairement à la crainte de l'audit (§3.1), les tables InnoDB/utf8mb4 examinées en détail (`t_article`, `t_agendas`) se sont révélées **correctement encodées** une fois vérifiées octet par octet (apostrophes typographiques ’, tirets – etc. corrects) — l'apparence de mojibake constatée pendant l'audit initial provenait de l'affichage dans le terminal Windows/Git Bash, pas d'une corruption réelle en base. `LegacyCleaner::text()` reste en place comme filet de sécurité pour les tables MyISAM/latin1 réellement suspectes (non vérifiées individuellement), mais la perte de données par corruption est probablement bien moindre que redouté.

**Hypothèses de mapping de statut posées faute de documentation métier** (à confirmer avec le client, ajustables en une ligne dans chaque commande) :
- `t_article.statut` : 0→archived, 1→published, 2→pending.
- `t_news.is_enabled` : D→archived, T→published, F→pending, S→archived (pas d'équivalent "rejected" pour les news).
- `t_agendas.status` : 1→published (si date future), sinon expired/draft selon la date réelle ; 5/6→cancelled.

**Bug corrigé après coup — normalisation des emplacements de sliders** : les noms de page côté legacy (`t_slider_page.name`) sont français/abrégés (`accueil`, `bannonces`, `lanuit`, `rencontres`, `billboardG`/`billboardD`...), différents de la convention du nouveau site (`home`, `annonces`...). `MigrateSliders::PAGE_MAP` les normalise, en mappant explicitement à `null` (donc ignorés) les emplacements qui n'ont plus de sens dans le nouveau design (`rencontres` : module archivé ; `billboardG`/`billboardD` : skyscrapers de l'ancienne homepage 3-colonnes, absents de la nouvelle). **Piège PHP rencontré et corrigé** : `self::PAGE_MAP[$name] ?? $name` ne fonctionne PAS pour mapper vers `null` — l'opérateur `??` traite une valeur `null` explicite comme "absente" et retombe sur `$name` ; il faut `array_key_exists($name, PAGE_MAP) ? PAGE_MAP[$name] : $name`. À garder en tête pour tout futur mapping legacy avec des cibles `null` intentionnelles.

### Résultat final de la migration (vérifié en base, 2026-08-23)

| Table | Lignes migrées | Table | Lignes migrées |
|---|---|---|---|
| `categories` | 1 447 | `news` | 6 191 |
| `areas` | 3 845 | `news_comments` | 2 (12 ignorés, marqués supprimés en legacy) |
| `amenities` | 19 | `sliders` | 135 |
| `listings` | 2 978 | `slider_placements` | 810 |
| `listing_category` | 9 414 | `contact_messages` | 54 |
| `listing_amenity` | 391 | `seo_meta` | 1 294 |
| `event_categories` | 25 | `redirects` | 6 710 (amorce, voir limite ci-dessus) |
| `events` | 18 724 (299 ignorés, date invalide) | `click_events` | 2 454 647 (332 099 ignorés, type/entité non résolus) |
| `cinemas` | 28 | `pages` | 17 |
| `movies` | 17 304 | `classifieds` | 0 (volontaire, module non migré) |
| `screenings` | 40 740 | | |
| `screening_times` | 305 913 | | |
| `movie_comments` | 8 | | |

Tous les tests automatisés (31) passent après migration, y compris avec les corrections de schéma ci-dessus.

### Pages publiques annuaire / agenda / cinéma (Phases 6-8, partiel)

- **Annuaire** (`ListingController`, `resources/views/annuaire/`) : `/annuaire` (grille + sidebar catégories + recherche), `/annuaire/{category}` (inclut automatiquement les sous-catégories), `/annuaire/fiche/{listing}` (fiche détail), **`/annuaire/deposer`** (dépôt public, voir ci-dessous). Distinction stricte gratuite/payante appliquée dans la vue (brief §5) : une fiche gratuite n'affiche jamais email/site web/description riche même si ces champs sont renseignés en base. Structured data `LocalBusiness` ou `Restaurant` (via `Listing::isRestaurant()`) sur la fiche détail.
- **Agenda** (`EventController`, `resources/views/agenda/`) : `/agenda` (liste + filtre catégorie + recherche + navigation date + **calendrier visuel**, `?view=calendar`), `/agenda/{slug}` résout **catégorie d'abord, événement ensuite** sur la même URL — c'est ce qui donne au menu THÉÂTRE (`/agenda/theatre`) sa propre URL sans être une entité séparée, conformément au brief §6. Structured data `Event` (avec `Offer`/`Place` si prix/lieu connus).
- **Cinéma** (`CinemaController`, `resources/views/cinema/`) : `/cinema` (films actuellement programmés + liste des salles), `/cinema/films/{movie}` (séances groupées par salle, avis), `/cinema/salles/{cinema}` (films groupés par salle). "Actuellement programmé" = au moins une séance dont la fenêtre `start_date`/`end_date` couvre aujourd'hui. Structured data `Movie`/`MovieTheater`.
- **`x-ui.breadcrumb`** : fil d'Ariane visuel + `BreadcrumbList` JSON-LD, réutilisé sur les 3 domaines.
- **Bug réel corrigé pendant l'implémentation** : `CinemaController::currentlyValid()` était type-hintée `Builder` strictement, mais reçoit un `Relation` (`HasMany`) quand appelée depuis une closure de eager-loading contraint (`$movie->load(['screenings' => fn ($q) => ...])`) — `TypeError` sur **toute** fiche film/salle. Corrigé en acceptant `Builder|Relation`. Symptôme observé avant correctif : requêtes très lentes (jusqu'à 68s en premier appel, compilation Blade comprise) puis 500 — bon rappel de toujours vérifier `storage/logs/laravel.log` plutôt que de se fier à l'apparence d'un timeout réseau.
- **Simplification assumée** : les URLs de catégorie annuaire sont à un seul segment (`/annuaire/{slug}`, résolu à n'importe quel niveau de la hiérarchie) plutôt que de reproduire le chemin imbriqué `/annuaire/cat/scat1/scat2` du legacy — plus simple et tout aussi indexable ; à revoir si le client tient à la profondeur visible dans l'URL.
- Tests : `tests/Feature/PublicContentPagesTest.php` (9 tests : rendu index/détail, statuts publiés/non publiés, résolution de slug agenda, film sans séance courante, calendrier).
- Vérifié en conditions réelles : les routes testées en HTTP contre les vraies données migrées (`php artisan serve` + `curl`), pas seulement via les tests SQLite.

**Calendrier visuel agenda** (`?view=calendar` sur `/agenda` et `/agenda/{categorie}`, brief §6) : grille mensuelle (6 semaines, lundi en premier), un point sur les jours ayant au moins un événement, navigation mois précédent/suivant (`?month=Y-m`), clic sur un jour = bascule vers la vue liste filtrée sur ce jour (réutilise le filtre `?date=` déjà existant). **Simplification assumée** : le comptage par jour se base sur `start_date` uniquement (jour de début), pas sur toute la plage `start_date`→`end_date` — sinon un festival d'une semaine « remplirait » toute la vue calendrier de points. Le filtre par jour précis (`?date=`, vue liste) reste exact, lui.

**Bug préexistant trouvé et corrigé en construisant cette vue** : plusieurs `<x-ui.button href="{{ ... }}">` (navigation veille/lendemain, réinitialiser) utilisaient `href="{{ expr }}"` au lieu de `:href="expr"` — la première forme échappe la valeur AVANT de la passer au composant, qui l'échappe une seconde fois lui-même (`href="{{ $href }}"` dans `components/ui/button.blade.php`), transformant tout `&` de séparation de paramètres en `&amp;amp;` et cassant l'URL dès que la requête portait **au moins deux** paramètres simultanés (ex. `?date=...&q=...`) — invisible avec un seul paramètre, jamais détecté avant faute d'avoir testé une combinaison à 2 paramètres. Corrigé (3 occurrences pré-existantes + 2 nouvelles dans le calendrier). Test de non-régression dédié : `test_agenda_date_navigation_does_not_double_encode_with_multiple_query_params`. **À vérifier ailleurs** : ce piège (`href="{{ }}"` vs `:href="..."`) peut exister sur d'autres composants Blade custom du projet, pas auditée exhaustivement au-delà de `x-ui.button`.

**Dépôt public de fiche annuaire** (`/annuaire/deposer`, brief §5) — même modèle que le dépôt d'annonce (Phase 9) : workflow de modération STRICT et non contournable, `ListingController::store()` force toujours `tier = 'free'` et `status = 'pending'`, jamais de valeur envoyée par le visiteur (testé explicitement : tentative d'injection de `status`/`tier` dans le payload, sans effet). Honeypot anti-spam nommé `url_verification` (pas `website`, comme pour les annonces/contact) — `Listing` a un vrai champ `website` fillable, un nom identique aurait fait échouer toute soumission légitime renseignant son site. Une seule catégorie sélectionnable côté public (l'admin peut en ajouter via `ListingResource` après validation). Lien "+ Ajouter mon établissement" sur `/annuaire` (sidebar + état vide). Tests : `tests/Feature/PublicFormsAndNewsTest.php` (statut/tier toujours forcés, fiche non visible tant que `pending`, honeypot rejeté). Vérifié en HTTP réel (`php artisan serve` + `curl`) : formulaire, noms de champs, non-régression du routage catégorie (`/annuaire/deposer` doit être déclaré avant la route wildcard `/annuaire/{categorySlug}`, même piège que pour les annonces).

### Actualités, Annonces et Contact publics (Phase 9 + brief §11)

- **Actualités** (`NewsController`, `resources/views/actualites/`) : `/actualites`, `/actualites/{slug}` (résout catégorie puis article, même pattern que l'agenda — cohérence délibérée entre domaines). Corrige au passage les liens déjà présents sur la homepage (`/actualites/{slug}`) qui pointaient vers des routes inexistantes. Structured data `NewsArticle`.
- **Annonces** (`ClassifiedController`, `resources/views/annonces/`) : `/annonces`, `/annonces/{slug}` (catégorie ou annonce), `/annonces/deposer` (formulaire public) + `POST /annonces`. **Le workflow de modération est non contournable par construction** : `store()` force toujours `status = 'pending'` quoi que le visiteur envoie (même une tentative d'injection explicite de `status=published` dans le payload est ignorée, testé) ; seules les actions admin `Valider`/`Refuser` de `ClassifiedResource` changent ce statut. Honeypot anti-spam (`website`, champ invisible en CSS) sur ce formulaire et celui de contact.
- **Contact** (`ContactController`, `resources/views/contact/`) : `/contact`, page refaite (brief §11), stockage dans `contact_messages` (déjà administrable), throttle 5/min + honeypot.
- **Bug réel corrigé** : `NewsCategory` et `ClassifiedCategory` avaient été créés sans le trait `HasSeoMeta` (oubli lors du scaffold initial) — `resolveSeo()` plantait en `BadMethodCallException` dès qu'une page catégorie actualités était visitée. Détecté par le test automatisé, pas en production.
- Tests : `tests/Feature/PublicFormsAndNewsTest.php` (8 tests, dont 3 dédiés à la non-contournabilité de la modération : statut forcé, injection de `status` ignorée, honeypot).
- Vérifié en HTTP réel contre les données migrées.

### SEO/GEO, redirections et sitemap (Phase 11, amorce)

- **Redirections 301** (`Controller::redirectOrAbort()`, appelée par chaque contrôleur de fiche/détail avant son 404 final) : consulte la table `redirects` (6 710 entrées migrées, §10) et incrémente `hits_count` à chaque déclenchement.
- **Piège réel rencontré et corrigé** : `Route::fallback()` (approche initiale) ne fonctionne QUE pour des URLs dont la forme ne correspond à AUCUNE route déclarée. Or l'essentiel des redirections migrées partage la même forme que les routes actuelles (`/annuaire/fiche/{ancien-slug}` → route existante `/annuaire/fiche/{slug}`), donc le routeur "matchait" déjà la route et ne tombait jamais dans le fallback. **Toutes les routes de fiche/détail ont dû passer d'un route-model-binding implicite (`{listing:slug}`) à une résolution manuelle** (`string $slug` + lookup + `redirectOrAbort()` si introuvable) — `ListingController::show`, `CinemaController::showMovie/showCinema`, et les méthodes `bySlug` d'Agenda/Actualités/Annonces qui utilisaient `firstOrFail()`. `Route::fallback()` reste en place pour les rares cas où la forme d'URL legacy diffère totalement (aucun exemple concret dans les 6 710 entrées migrées, mais utile pour des ajouts manuels futurs).
- **Sitemap** (`php artisan sitemap:generate`, `app/Console/Commands/GenerateSitemap.php`) : génère `public/sitemap.xml` en fichier statique (pas de recalcul par requête, contrairement au `SiteMapController` legacy sans cache — audit §4). Inclut catégories actives, fiches publiées, catégories/événements à venir de l'agenda, salles + films actuellement programmés, actualités publiées, annonces publiées. **Exclut volontairement** les 18k+ événements historiques expirés et l'essentiel des 17k+ films (seuls ceux avec une séance en cours) pour ne pas produire un sitemap disproportionné par rapport au contenu réellement pertinent. Testé contre les vraies données : **4 036 URLs** générées. Planifié quotidiennement (`routes/console.php`, `Schedule::command('sitemap:generate')->daily()`).
- **`robots.txt`** (`public/robots.txt`) : bloque `/admin` et `/track-click`, référence le sitemap.
- **Non testé en PHPUnit délibérément** : `sitemap:generate` écrit un vrai fichier sous `public/` (chemin partagé, pas isolé par environnement de test) — l'exécuter dans un test écraserait le sitemap réel avec les données vides de la base de test SQLite. Vérifié uniquement manuellement contre les vraies données (ci-dessus) ; à revoir si un test automatisé est jugé nécessaire (ex. injecter un chemin de sortie configurable).
- Tests : `tests/Feature/RedirectsTest.php` (4 tests : redirection connue, redirection inactive ignorée, chemin inconnu en 404, fonctionnement sur les 4 domaines annuaire/agenda/cinéma/actualités).

### Sécurité (Phase 12, amorce)

Réaction directe aux failles catastrophiques constatées dans le legacy à l'audit (injection SQL systémique, `BOController` sans authentification, mots de passe non salés — voir §4) : chaque mesure ci-dessous a un équivalent legacy documenté comme défaillant.

- **En-têtes de sécurité globaux** (`App\Http\Middleware\SecurityHeaders`, appliqué à toutes les réponses via `bootstrap/app.php`) : `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` restrictive. Le legacy n'en posait aucun.
- **Injection SQL** : structurellement écartée — tout le code applicatif (hors commandes `migrate:*`, lecture seule sur `toulouseweb_old`) passe par Eloquent/Query Builder paramétré, jamais de concaténation SQL brute comme le faisait systématiquement le legacy (`CategoryController`, `BOController`, etc., voir §4).
- **Autorisation** : pas d'équivalent `BOController` (CRUD générique sans whitelist ni auth sur n'importe quelle table) — chaque ressource Filament est une classe PHP explicite, l'accès au panel exige un rôle (`User::canAccessPanel`), voir §13 Phase 4.
- **Mots de passe** : `Hash::make()` (bcrypt) natif Laravel partout, jamais de MD5/SHA1 non salé comme `t_user`/`t_toulousains` en legacy.
- **Uploads** : limites de taille (4 Mo) et type (image) explicites sur tous les champs Filament (`ListingResource`, `ClassifiedResource`, `EventResource`, `NewsResource`, `SliderResource`) — le legacy (`SharedController::uploadFile`) ne validait ni le nom de fichier ni le dossier cible (path traversal potentiel).
- **XSS** : tout contenu soumis par un visiteur (annonces, contact) passe par `e()` avant tout affichage (`nl2br(e(...))`) ; seul le contenu **saisi par un admin authentifié** via l'éditeur riche Filament (actualités) est affiché brut (`{!! !!}`), un choix assumé et documenté, pas un oubli.
- **Anti-spam** : honeypot sur les formulaires publics (annonces, contact) + limitation de débit (`throttle:5,1`) sur leurs routes `POST`.
- **Cookies de session** : `SESSION_SECURE_COOKIE` documenté comme obligatoire en production dans `.env.example` (HTTPS uniquement), `http_only`/`same_site=lax` déjà par défaut Laravel.
- **Pas de fuite d'information** : `.env.example` rappelle explicitement `APP_DEBUG=false` en production (sinon Laravel affiche les traces complètes, un problème documenté existant potentiellement aussi côté legacy).
- Tests : `tests/Feature/SecurityTest.php` (en-têtes présents sur pages publiques ET admin, échappement HTML du contenu utilisateur affiché, panel admin non accessible sans authentification).
- Vérifié en HTTP réel (en-têtes confirmés sur une vraie réponse serveur).

**Volontairement pas encore fait** : audit de dépendances (`composer audit`), Content-Security-Policy stricte (risque de casser Alpine/Vite sans réglage fin), limitation de débit sur `/admin/login` (à vérifier si le comportement par défaut de Filament suffit), scan de vulnérabilités automatisé, tests de charge/performance (Phase 12 complète), procédure de déploiement (Phase 14).

### Import des images disponibles dans `old/` (brief §16)

**⚠️ Correction d'audit majeure (2026-08-24)** : ce paragraphe affirmait auparavant que `old/` ne contenait que 48 fichiers image au total. C'était faux — cette première recherche n'avait exploré que la racine de `old/`, sans descendre dans `old/backEnd/public/`, qui contient à lui seul **plus de 33 000 fichiers image (~3,2 Go)**, organisés par domaine (`cine_film/`, `news/`, `article/` + `article/compressed/`, `agenda/`, `icone/`, `contacsites/`, `bonsplans/images/`...). Le client a signalé cette omission ; l'exploration complète confirme qu'une écrasante majorité des visuels de contenu (annuaire, films, actualités, agenda, sliders) est bien présente localement, contrairement à ce qui avait été conclu. Erreur reconnue et corrigée ici plutôt que masquée.

**Méthode** : chaque colonne image legacy (`t_article.image`, `t_carousel.image`, `t_news.img_path`, `t_cine_film.image`, `t_agendas.image`, `t_icone.image`, `t_contact_sites.image`, `t_sliders.img`) a été croisée avec le contenu réel de `old/backEnd/public/` via `App\Services\Migration\LegacyImageImporter` : résolution par nom de fichier exact, puis par nom de base sans extension (insensible à la casse) — le site servait des variantes `.webp` compressées dont le nom de base est identique à l'original mais l'extension diffère, d'où l'intérêt de cette seconde passe. Chaque commande `images:*` copie les fichiers retrouvés vers `storage/app/public/{domaine}/` et met à jour la colonne concernée (ou attache le fichier à une collection `Spatie\MediaLibrary` pour l'annuaire).

**Résultat, vérifié en base et en HTTP réel** :

| Domaine | Commande | Trouvés / total avec valeur | Détail |
|---|---|---|---|
| Sliders (`t_sliders.img`) | `images:sliders` | **132 / 135** | Recherche large sur tout `old/backEnd/public/` (aucun répertoire dédié, fichiers dispersés — retrouvés entre autres dans `agenda/`, `article/`) |
| Films/affiches (`t_cine_film.image`) | `images:movies` | **2 521 / 2 787** valeurs locales (6 703 autres déjà en URL AlloCiné absolue, inchangées) | `movies.poster`, jamais écrasé si déjà une URL absolue ou déjà résolu |
| Actualités (`t_news.img_path`) | `images:news` | **2 250 / 5 558** | Le reste n'a jamais été mis en cache dans ce dépôt de référence |
| Fiche annuaire, photo principale (`t_article.image`) | `images:listings` | **617 / 619** | Collection MediaLibrary `logo` sur `Listing` |
| Fiche annuaire, galerie (`t_carousel.image`, FK `id_article`) | `images:listings` | **1 172 / 1 204** | Collection MediaLibrary `gallery` — **`t_carousel` n'avait jamais été migré avant ce chantier** (plusieurs photos par fiche, fonctionnalité réelle jusque-là non exploitée) |
| Événements (`t_agendas.image`) | `images:events` | **897 / 968** valeurs locales (le reste : URLs externes déjà absolues, scraping tiers — lebikini.com, openagenda.com..., inchangées) | |
| Pictogrammes d'équipements (`t_icone.image`) | `images:amenities` | **19 / 19** | |
| Logos sites partenaires (`t_contact_sites.image`) | `images:partner-sites` | **8 / 8** | Nécessitait `migrate:partner-sites` au préalable — **`t_contact_sites` n'avait jamais été migré** (table `partner_sites` scaffoldée mais vide, oubli de l'audit initial comblé ici) |

Vues publiques mises à jour en conséquence pour exploiter ces données désormais réelles (elles ne l'étaient pas avant, la donnée étant absente) : `annuaire/show.blade.php` (photo principale en hero + galerie + `image` dans le JSON-LD LocalBusiness/Restaurant), `annuaire/index.blade.php`, `home.blade.php` (cartes annuaire avec vignette).

**Décision architecturale délibérée : les fichiers importés ne sont PAS commités dans git.** Le sous-ensemble retrouvé pèse plusieurs centaines de Mo — les commiter gonflerait durablement le dépôt (clones lents, pas de diff utile sur du binaire). Ces commandes `images:*` suivent donc exactement la même philosophie que les commandes `migrate:*` : ce sont des commandes **rejouables contre une vraie source de données**, jamais un instantané figé dans un commit. `storage/app/public/` reste couvert par le `.gitignore` par défaut de Laravel (à l'exception des 2 fichiers slider historiquement forcés en git lors d'un lot de travail antérieur, négligeables en taille). **Conséquence pour le déploiement (Phase 14, à documenter en détail)** : ces commandes doivent être rejouées sur l'environnement cible contre une copie de `old/backEnd/public/` (ou tout accès équivalent au stockage de l'ancien site), exactement comme `migrate:*` est rejouée contre une copie de `toulouseweb_old`.

**Bugs réels trouvés et corrigés pendant ce travail** :
- `Screening::$casts` déclarait `'date'` sans format explicite (`'date:Y-m-d'`) — sans lien avec les images, découvert en Phase 8, documenté §11.
- `ResolvesImageUrl::resolveImageUrl()` ne laissait pas passer les valeurs `data:` (URI base64) — constaté sur quelques lignes `t_cine_film.image` qui stockent directement une image encodée plutôt qu'un nom de fichier. Corrigé, testé (`ImageUrlResolutionTest`).
- `ImportMovieImages` (version initiale) excluait à tort tout film dont `poster` était déjà non-vide — hors la plupart des 2 644 films concernés avaient justement un `poster` non-vide mais **cassé** (nom de fichier brut, jamais résolu). Corrigé pour ne préserver que les URLs absolues ou déjà résolues.
- `t_contact_sites` (sites partenaires) et `t_carousel` (galerie annuaire) n'avaient jamais été migrés du tout (`partner_sites` scaffoldée mais vide ; pas de table de galerie). Comblé par `migrate:partner-sites` (nouvelle commande) et l'usage de la collection MediaLibrary `gallery` déjà prévue (mais jamais peuplée) sur `Listing`.

**Ce qui reste non récupérable ou non traité** :
- `bonsplans/images/` (1 156 fichiers, certains nommés `..._encadre_...`/`..._bon-plan_...`) : recherché exhaustivement dans toutes les colonnes image de `toulouseweb_old` (y compris `t_carousel`, `t_article`) sans trouver la moindre correspondance — ces fichiers sont **orphelins**, la table qui les référençait autrefois n'existe plus dans ce dump. Non importés (aucune fiche à laquelle les rattacher) ; à ré-examiner uniquement si vous disposez d'un ancien schéma ou d'une sauvegarde plus complète.
- Répertoires non exploités faute de correspondance claire en base (volumes marginaux) : `agendas/` (792 fichiers, doublon probable de `agenda/`), `cinema/` (9), `homepage/` (5), `location/` (1), `customAnnuaire/` (2), `annonce/` (3), `agenda_categories/` (5), `contact/icones` + `contact/pub` (22) — non prioritaires, à investiguer seulement si un besoin précis se présente.
- Le reste des 40-75% de films/actualités/événements sans fichier local (voir tableau) n'a jamais été mis en cache dans ce dépôt de référence — récupérable uniquement via un accès direct au stockage de production.
- Qualité des données brutes non corrigée : quelques valeurs `t_cine_film.image` contiennent du texte de synopsis ou une URI base64 au lieu d'un nom de fichier (bug legacy, ignoré proprement sans planter) ; au moins un fichier recovré (`agenda/test9876.jpg`) pèse plus de 20 Mo (upload de test jamais optimisé) — l'optimisation/redimensionnement des images reste un futur sujet de performance (Phase 12), pas traité ici.

### Ce qui n'existe PAS encore

La proposition d'événement par le public (agenda). Le scraper cinéma AlloCiné existe (`scrape:cinema`, ci-dessus, 24 salles/27, fiches film + horaires précis) mais reste à vérifier en direct. Pas d'audit Search Console/logs pour les URLs legacy hors du périmètre couvert par la continuité de slug en base (voir §10) — `redirects:audit`/`missed_redirects` couvre désormais les 404 générées PAR ce dépôt en conditions réelles, mais pas un historique Search Console antérieur à sa mise en place. Cache applicatif, optimisation des requêtes N+1 à grande échelle et tests de charge (reste de la Phase 12), procédure de déploiement (Phase 14).

**Scraper agenda : investigation terminée, conclusion définitive (2026-08-24)** — contrairement au cinéma où `autoUpdateCinemaAllocine` était un vrai mécanisme fonctionnel (juste mal documenté), **le scraper agenda n'existe nulle part dans le code legacy final** :
- `t_agenda_scrapping` liste 18 sources (Zenith, Théâtre du Capitole, Stade Toulousain, TFC, Bikini, Odyssud, Théâtre Garonne...) avec une colonne `lien` du type `updateAgendaforZenith`, `updateAgendaRugby`, etc. — qui ressemblent à des noms de méthode de contrôleur.
- `old/backEnd/routes/api.php` (lignes 203-221) déclare bien 18 routes `Route::get('updateAgendafor{Venue}/{isLaunch?}', 'AgendaController@updateAgendafor{Venue}')` correspondant exactement à ces noms.
- **Mais `AgendaController.php` ne contient AUCUNE de ces méthodes** (15 méthodes au total, toutes du CRUD classique — `getCategory`, `getBydate`, `getByCategory`, `getById`, `search`, `categories`, `areas`, `add`... rien d'autre). Confirmé par recherche exhaustive dans tout `old/backEnd/app/` : ces noms de méthode n'existent nulle part dans le code.
- Conclusion : ces 18 routes sont **mortes** (elles planteraient avec une `BadMethodCallException` si jamais appelées) — le scraper a soit été supprimé sans que les routes/la table de config ne soient nettoyées, soit n'a jamais été terminé. Dans les deux cas, **il n'y a aucune implémentation de référence à reproduire**, contrairement au cinéma.
- **Décision produit requise avant toute implémentation** : construire un scraper agenda signifierait partir de zéro (pas une réécriture) — à décider avec vous quelles sources cibler en priorité et par quel mécanisme (flux iCal/RSS quand la salle en propose un, sinon scraping HTML dédié par site, au cas par cas). Non entamé, en attente d'arbitrage.
