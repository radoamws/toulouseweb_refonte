# ToulouseWeb — Documentation technique

> Document vivant : à mettre à jour à chaque modification importante du code ou de l'architecture.
> Dernière mise à jour : 2026-08-25 — **Phases 1, 2, 5, 6, 8 terminées ; Phase 3 (design system + layout), Phase 4 (admin) et Phase 7 (agenda, scraper des 12 sources réelles de la liste de cron de production) bien avancées ; premières briques de la Phase 10 (homepage) livrées.**

---

## 0. État du projet

| Phase | Statut |
|---|---|
| 1. Audit complet de l'ancien site et de la base | ✅ Terminé (§1-6) |
| 2. Architecture technique et base de données | ✅ Terminé (§7-11) |
| 3. Design system & layout | ✅ Palette/typographies/composants Blade de base livrés (§13), et toutes les pages de contenu (annuaire/agenda/cinéma/actualités/annonces/homepage) construites dessus — voir phases 6-10 |
| 4. Administration | 🟡 21 ressources Filament créées et testées (dont gestion utilisateurs/rôles) + relation manager Séances (Phase 8) + page Paramètres du site (dont SEO/Analytics globaux) + dashboard stats de clics (§13) |
| 5. Migration des données | ✅ Terminé — 11 commandes `migrate:*` exécutées avec succès contre `toulouseweb_old` réelle (§13, dont `migrate:partner-sites` ajoutée le 2026-08-24 — table oubliée à l'audit initial) + 7 commandes `images:*` ayant réimporté l'écrasante majorité des visuels de contenu retrouvés sous `old/backEnd/public/` (33 000+ fichiers, voir §13) |
| 6. Annuaire | 🟡 Pages publiques (index par catégorie + recherche texte + **filtre par ville**, fiche détail) livrées et vérifiées avec les vraies données, désormais avec photo principale + galerie réelles + dépôt public de fiche (modération stricte, tier toujours gratuit) — voir §13 ; recherche géographique par RAYON (lat/lng) hors scope — la base legacy n'a jamais stocké de coordonnées, nécessiterait un service de géocodage externe |
| 7. Agenda / événements / théâtre | 🟡 Pages publiques (index + filtre catégorie dont "theatre", fiche détail, calendrier visuel) livrées ; **proposition d'événement par le public livrée** (`/agenda/proposer`, modération stricte identique aux annonces/annuaire) ; scraper agenda **construit pour les 12 sources réelles de la liste de cron de production** (reconstruites à partir du vrai code legacy fourni par le client), vérifié en direct — 10/12 pleinement fonctionnelles, 2 avec limite connue documentée (sites source refondus depuis le legacy) — voir §13 |
| 8. Cinéma | 🟡 Pages publiques + scraper AlloCiné réécrit (`scrape:cinema`, une source par salle — 25/28 — fiches film + salle + horaires précis, planifié quotidien) + relation manager Séances (saisie manuelle en complément) ; **scraper vérifié en direct le 25/08/2026** contre les 25 vraies sources (accès réseau désormais disponible depuis ce sandbox) : 25/25 exécutions réussies, 397 séances trouvées, voir §13 |
| 9. Annonces | 🟡 Pages publiques + dépôt avec workflow de modération strict (jamais de publication automatique, honeypot anti-spam) livrés et testés (§13) |
| 10. Homepage | 🟡 Fonctionnelle et vérifiée avec les vraies données migrées (slider, actus, agenda, cinéma, annuaire, annonces désormais dépôt-able) |
| Contact (brief §11, hors numérotation de phase) | ✅ Page refaite, formulaire sécurisé (honeypot + throttle), stockage dans `contact_messages` déjà administrable |
| 11. SEO/GEO, URLs, redirections | 🟡 Redirections 301 opérationnelles sur les 6 710 entrées migrées (annuaire/cinéma/actualités/catégories), sitemap.xml généré (4 036 URLs), robots.txt ; canonical/OG/Twitter/JSON-LD déjà posés depuis les phases précédentes ; **maillage interne** sur toutes les pages de détail, **gestion des contenus expirés** (`content:mark-expired`), **`/llms.txt`** pour l'optimisation GEO/AI Search |
| 12. Performance & sécurité | 🟡 Sécurité : en-têtes de sécurité globaux, limites d'upload Filament, cookies de session sécurisés en prod, tests dédiés. Performance : audit des requêtes réelles, 2 index manquants identifiés et ajoutés (`listings.city`, `click_events.created_at` — la table de 2,78M lignes tournait en full scan sur 3 requêtes du dashboard admin à CHAQUE chargement) — voir §13 |
| 13. Tests complets | 🟡 138 tests / 376 assertions (feature + unit), couvrant modération/sécurité/scraping/migration/SEO sur tous les modules livrés — pas de campagne de charge/perf dédiée |
| 14. Préparation au déploiement | 🔜 Non démarrée |

Le dossier `old/` contient l'ancien site (backend Laravel 7 + frontend Nuxt 2), conservé en lecture seule pour référence. La base `toulouseweb_old` contient les données de production, non migrées. La base `toulouseweb` porte désormais le **schéma cible complet** (§9) et un compte admin. Voir §13 pour le détail de ce qui est réellement codé à date, §14 pour la procédure de déploiement.

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
10. `migrate:classifieds` — `t_annonce`+`t_annonce_categ` → `classifieds` (ajouté le 06/09/2026, voir §22 — absente du plan initial, volume réel de seulement 3 lignes)
11. `migrate:click-stats` — **délibérément PAS exécutée en production** depuis la demande client du 05/09/2026 : les statistiques du dashboard doivent repartir de zéro depuis cette refonte, pas continuer l'historique legacy (voir §21, `stats:reset`). Commande conservée dans le code pour un environnement de démo/dev qui voudrait un historique réaliste, mais volontairement omise de la séquence de cutover prod.

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
| `scrape:events` | Scraping agenda (sources dans `scraper_sources`, type `agenda`) | Quotidien à 5h30 | ✅ Implémenté pour 12 salles (liste cron de production réelle), 10/12 pleinement fonctionnelles (§13 — voir détail, réaudité §17) |
| `content:mark-expired` | Statut `expired` sur événements ET annonces passés (fusionne `events:archive-past`/`classifieds:expire`, jamais écrites séparément) | Quotidien à 4h30 | ✅ Implémenté (voir `MarkExpiredContent`) |
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

## 12. Plan de développement par phases (mise à jour post-décisions)

| Phase | Contenu | Statut |
|---|---|---|
| 1. Audit | Analyse complète ancien site + base — voir §1-6 | ✅ Terminé |
| 2. Architecture & BDD | Stack, schéma cible, plan de migration — voir §7-11 | ✅ Terminé |
| 3. Design system & layout | Scaffold Laravel + Filament, Tailwind config, composants Blade de base, layout public | ✅ Fait — composants de base + toutes les pages de contenu (annuaire/agenda/cinéma/actualités/annonces/homepage) construites dessus (§13) |
| 4. Administration | Resources Filament par entité, rôles/permissions | ✅ Fait — 21 ressources, gestion utilisateurs/rôles, dashboard stats (§13) |
| 5. Migration des données | Exécution des commandes `migrate:*` sur environnement local | ✅ Fait — contre `toulouseweb_old` réelle (§13) |
| 6. Annuaire | Listings, catégories, recherche, fiches payantes/gratuites, dépôt public | 🟡 Fait (§13) — reste : recherche géographique par RAYON (lat/lng), filtre par ville livré en attendant |
| 7. Agenda / événements / théâtre | Listing, filtres, calendrier, scraping événements, proposition publique | 🟡 Fait — scraping construit pour les 12 sources réelles de la liste de cron de production (§13), 10/12 pleinement fonctionnelles ; dépôt public livré |
| 8. Cinéma | Modèle, scraping AlloCiné réécrit (une source par salle), UI | ✅ Fait — vérifié en direct (25/25 sources, §13) |
| 9. Annonces | Dépôt public, modération admin, catégories dynamiques | ✅ Fait (§13) |
| 10. Homepage | Slider admin, sections dynamiques | ✅ Fait — vérifiée avec les vraies données migrées (§13) |
| 11. SEO/GEO, URLs, redirections | `seo_meta`, sitemap, redirections, structured data | 🟡 Fait — redirections 301, sitemap.xml, robots.txt, canonical/OG/Twitter/JSON-LD opérationnels (§13) |
| 12. Performance & sécurité | Cache, index, audit sécurité complet | 🟡 Sécurité amorcée (en-têtes, uploads, cookies — §13) ; audit de performance fait, 2 index manquants corrigés (§13) |
| 13. Tests | Tests fonctionnels critiques (modération, migration, SEO) | 🟡 138 tests / 376 assertions couvrant tous les modules livrés ; pas de campagne de charge/perf dédiée |
| 14. Déploiement | Procédure cPanel, cron, checklist mise en prod | 🔜 À venir |

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
- **Vrai logo ToulouseWeb** (26/08/2026, demande client explicite) : `SiteSetting::current()` pointe par défaut vers `/branding/toulouseweb-logo.png` — le VRAI logo de production, récupéré directement sur `https://toulouseweb.com/_nuxt/img/logo.c085ee1.png` et confirmé **identique octet pour octet** (MD5) à la copie déjà présente dans `old/client-app/assets/img/logo.png` (donc pas une supposition — vérifié). Volontairement commité en asset STATIQUE (`public/branding/`), PAS uploadé via le disque `public` habituel (`storage/app/public/`, ignoré par git — réservé au contenu réellement déposé en environnement réel, voir §16) : un logo de marque doit être présent dès un premier `git clone`, pas dépendre d'un re-upload manuel. Reste modifiable depuis l'admin (`FileUpload` de `SiteSettings`, qui écrirait alors bien sur le disque `public` et prendrait le dessus).
  - **Limite technique découverte** : ce logo réel est un texte BLANC sur fond transparent (prévu pour un fond sombre côté site legacy) — invisible tel quel sur l'entête clair de cette refonte. Plutôt que d'utiliser un logo à moitié invisible ou de refondre toute la palette de l'entête, celui-ci est affiché dans un petit encart sombre (`bg-ink-900` arrondi) au sein de l'entête clair — le pied de page (déjà à fond sombre) l'affiche nativement sans encart.
  - **Favicon** : la coccinelle de la marque (`public/branding/toulouseweb-icon.png`, récupérée sur `https://toulouseweb.com/apple-touch-icon-180x180-precomposed.png`) posée en `<link rel="icon">`/`<link rel="apple-touch-icon">` dans le layout — remplace l'absence totale de favicon explicite (`public/favicon.ico` générique Laravel restait le seul repli).
  - Tests : `tests/Feature/SiteSettingsTest.php` (asset réel présent sur disque + référencé dans le HTML rendu, entête ET pied de page, favicon).
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
- **`robots.txt`** (`public/robots.txt`) : bloque `/admin` et `/track-click`, référence le sitemap. Aucun user-agent IA (GPTBot, ClaudeBot, PerplexityBot...) n'est exclu — le reste du site est délibérément ouvert au crawl, y compris IA (voir GEO/AI Search ci-dessous).
- **Maillage interne** (brief §13, ajouté le 25/08/2026) : chaque page de détail affiche désormais un bloc "contenu similaire" — `EventController::show()` (mêmes catégories, à venir), `CinemaController::showMovie()`/`showCinema()` (autres films à l'affiche / autres salles actives), `ClassifiedController::show()` (même catégorie). `ListingController::show()` et `NewsController` l'avaient déjà (Phases 6/9).
- **Gestion des contenus expirés / événements passés** (brief §13) : `MigrateEvents.php` posait `status=expired` une seule fois, au moment de la migration — rien ne maintenait cet état à jour depuis. Nouvelle commande `content:mark-expired` (planifiée quotidiennement 4h30, `routes/console.php`) qui fait passer `Event`/`Classified` de `published` à `expired` une fois leur date dépassée. **Ne protège PAS le public d'un affichage de contenu périmé** — c'était déjà géré par filtrage direct sur la date (`Event::scopeUpcoming()`, filtre `expires_at` de `ClassifiedController::renderIndex()`), indépendamment de `status` — sert uniquement à garder l'admin (filtres `EventResource`/`ClassifiedResource`) cohérent. Tests : `tests/Feature/MarkExpiredContentTest.php` (5 tests).
- **GEO/AI Search** (brief §13, "optimisation GEO/AI Search") : `GET /llms.txt` (`LlmsTxtController`) — convention émergente llmstxt.org, un point d'entrée texte résumant le site et ses sections principales pour les agents IA qui le privilégient à un crawl HTML complet. Contenu dynamique (pas un fichier statique comme sitemap.xml — coût négligeable, pas de régénération nécessaire), alimenté par `SiteSetting::current()`. Complémentaire, pas redondant, avec robots.txt (aucun blocage IA) et les données structurées Schema.org déjà posées sur chaque page. Le choix architectural Blade SSR (par opposition à une SPA côté client) sert aussi directement le GEO : tout le contenu est déjà lisible sans exécution JavaScript, ce qu'un agent IA/LLM (comme un moteur de recherche classique) préfère de loin à du contenu injecté en JS.
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
- ⚠️ **`agendas/` (792 fichiers) N'ÉTAIT PAS un doublon de `agenda/`** — cette conclusion, écrite ici initialement, était erronée : c'était un second dossier legacy distinct, source manquante d'environ 82 % des images d'événements. Corrigé le 06/09/2026 (audit de cutover prod, §22) — `ImportEventImages` indexe désormais les deux dossiers.
- Répertoires non exploités faute de correspondance claire en base (volumes marginaux, confirmés orphelins par l'audit §22) : `cinema/` (9, icônes de gabarit statique — `t_cine`/`cinemas` n'ont jamais eu de colonne image), `homepage/` (5), `location/` (1), `customAnnuaire/` (2), `agenda_categories/` (5, ne correspond à aucune valeur réelle de `t_agenda_categories.icon`), `contact/icones` + `contact/pub` (22) — non prioritaires, à investiguer seulement si un besoin précis se présente. `annonce/` (3 fichiers) n'est PLUS orphelin : couvert par `images:classifieds` depuis le 06/09/2026 (§22).
- Le reste des 40-75% de films/actualités/événements sans fichier local (voir tableau) n'a jamais été mis en cache dans ce dépôt de référence — récupérable uniquement via un accès direct au stockage de production.
- Qualité des données brutes non corrigée : quelques valeurs `t_cine_film.image` contiennent du texte de synopsis ou une URI base64 au lieu d'un nom de fichier (bug legacy, ignoré proprement sans planter) ; au moins un fichier recovré (`agenda/test9876.jpg`) pèse plus de 20 Mo (upload de test jamais optimisé) — l'optimisation/redimensionnement des images reste un futur sujet de performance (Phase 12), pas traité ici.

### Ce qui n'existe PAS encore

La proposition d'événement par le public (agenda). Le scraper cinéma AlloCiné existe (`scrape:cinema`, ci-dessus, 24 salles/27, fiches film + horaires précis) mais reste à vérifier en direct. Pas d'audit Search Console/logs pour les URLs legacy hors du périmètre couvert par la continuité de slug en base (voir §10) — `redirects:audit`/`missed_redirects` couvre désormais les 404 générées PAR ce dépôt en conditions réelles, mais pas un historique Search Console antérieur à sa mise en place. Cache applicatif, optimisation des requêtes N+1 à grande échelle et tests de charge (reste de la Phase 12), procédure de déploiement (Phase 14).

### Scraper agenda — investigation approfondie et implémentation (brief §6/§21)

**Round 1 (2026-08-24)** : contrairement au cinéma où `autoUpdateCinemaAllocine` était un vrai mécanisme fonctionnel (juste mal documenté), l'audit du code (`AgendaController.php`, `routes/api.php`, `Console/Kernel.php`) n'avait trouvé AUCUN scraper agenda fonctionnel :
- `t_agenda_scrapping` liste 18 sources (Zenith, Théâtre du Capitole, Stade Toulousain, TFC, Bikini, Odyssud, Théâtre Garonne...) avec une colonne `lien` du type `updateAgendaforZenith`, `updateAgendaRugby`, etc.
- `old/backEnd/routes/api.php` (lignes 203-221) déclare bien 18 routes vers ces noms de méthode — **mais `AgendaController.php` n'en contient AUCUNE** (15 méthodes au total, toutes du CRUD classique). Confirmé par recherche exhaustive dans tout `old/backEnd/app/`.
- Conclusion round 1 : ces 18 routes sont mortes, aucune implémentation de référence à reproduire — décision produit demandée avant de construire quoi que ce soit.

**Round 2 (2026-08-25, sur nouvelle demande client)** — investigation élargie à TOUT `old/` (pas seulement `backEnd/app/Http/Controllers/`) :
- `old/a_traiter/` et `old/backup/` : notes et fichiers `.env` de sauvegarde, rien de pertinent.
- **`AgendaController::formatData()` (route `GET agenda/import`) : une vraie méthode d'import existe**, lisant des tables `agenda`/`events` (sans préfixe `t_`) pour alimenter `t_agendas`/`t_agenda_cat` — mais ces deux tables **n'existent plus dans le dump `toulouseweb_old` actuel** (`SHOW TABLES LIKE 'agenda'` : vide). Conclusion : mécanisme d'import ponctuel (bootstrap depuis un système antérieur), pas un scraper actif — même famille que les scripts `dupliquer`/`dupliquer2` trouvés dans `CinemaController` (code de migration ponctuelle, pas de production courante).
- `old/client-app/server/` (le Nuxt SSR) : uniquement gestion des redirections 301, rien côté scraping.
- `SortiesController`/`SoireesController` : modules "sorties"/"soirées" utilisateur (proches de Rencontres), sans rapport avec le scraping agenda.
- **Découverte décisive : les données réelles parlent.** `t_agendas.lien_detail` (lien de détail d'un événement) contient, pour des lignes **datées de la saison 2026-2027** (donc alimentées EN PRODUCTION, après la date de cette copie du dépôt), de vraies URLs vers de vrais sites de salles :

  | Domaine source | Volume (événements valides à date) | Accessible depuis ce sandbox |
  |---|---|---|
  | `ardei-soft.com` (+ www.) | ~92 | ✅ (200) — mais contenu chargé en JS via une plateforme de billetterie propriétaire obfusquée (`/SenousritPGI?JAVOPP=...`) |
  | `leventdessignes.fr` | 49 | à re-vérifier (404 sur le chemin deviné, site non totalement exploré) |
  | `le-bijou.soticket.net` | 37 | ✅ (200), non exploré en détail |
  | `theatre-cite.com` | 32 | ✅ (200, redirige vers `/programmation`) |
  | `openagenda.com` | 12 | agrégateur tiers, lien déjà absolu (rien à scraper) |
  | `casinosbarriere.com`, `odyssud.com` | 4 chacun | non explorés |

  Conclusion round 2 : le MÉCANISME de scraping (le code) reste introuvable dans cette copie de `old/` — mais les VRAIS sites cibles, eux, sont identifiés avec certitude et, pour plusieurs, accessibles depuis cet environnement (contrairement à AlloCiné/Pathé-Gaumont, bloqués par pare-feu). Conformément à la consigne du client ("ajuste/optimise si du scraping existe déjà, sinon construire from scratch"), et faute de code à ajuster, **construction from scratch, mais informée par de vraies cibles vérifiées** plutôt que devinée à l'aveugle.

**Implémentation livrée : `TheatreDeLaCiteDriver`** (theatre-cite.com, 32 événements réels) — le candidat le plus fiable (site propre, rendu côté serveur, classes CSS stables et sémantiques) :
- Page de programmation (`/programmation`) : cartes `.programmation-grid__item--evenements`, titre (`.programmation-grid__item__title__inner`), image (`img.desktop-image[data-original]`), date en texte libre français (`.programmation-grid__item__date` + `.period-heure` pour l'heure).
- Page de détail (une par événement, pour le lien de réservation réel et le tarif) : lien "Réserver" (`.../billets?&seance=...`), ligne d'information libre (`.spectacle__informations__content__line`).
- Parsing de date français robuste (`19 septembre 2026` + `10:00`), sans dépendance à la locale système — table de correspondance mois FR → numéro, comme `AllocineDriver::safeParseDate`.
- Dédoublonnage par `external_ref` (dernier segment de l'URL de détail), rattachement à `Area` (slug `tnt-theatre-de-la-cite`, déjà migré) et `EventCategory` (slug `theatre`).
- **Vérifié en conditions réelles, pas seulement en test** : `php artisan scrape:events` exécuté contre le vrai site → **30 événements trouvés, 30 créés, 0 ignoré**, vérifié en base MySQL réelle (titres/dates/images/liens de réservation corrects, y compris les accents — un doute initial sur un encodage `Journ�es` s'est révélé être un artefact d'affichage du client `mysql` en ligne de commande, pas un vrai problème de stockage : les octets UTF-8 réels sont corrects, confirmé en HTTP réel sur `/agenda/rendez-vous-complicite` où "Complicité" s'affiche correctement).
- Tests : `tests/Feature/ScrapeEventsTest.php` (création avec la structure HTML réelle du site, mise à jour sans doublon, carte à date illisible ignorée, échec amont géré proprement, source inactive non exécutée).
- Commande `scrape:events` (nouvelle, architecture identique à `scrape:cinema`), planifiée quotidiennement à 5h30, seedée via `AgendaScraperSourcesSeeder`.

**Round 3 (2026-08-25, le client fournit le VRAI code legacy)** — le client a personnellement ajouté le vrai code source de production à `old/backEnd/app/Http/Controllers/AgendaController.php` (passé de 171 à 4820 lignes), et fourni la vraie liste des 12 tâches cron de production (`updateAgendafor{Salle}` sur `toulouseweb.com/backend/public/api/`) :  Zenith, Cite, CasinoBarriere, Garonne, Leventdessignes, Odyssud, Escale, GrandRond, Interprete, Metropole, Bijou, Ardei. Ce round remplace intégralement la stratégie de reverse-engineering à l'aveugle du Round 2 par une lecture exhaustive de ce vrai code, venue par venue — avec une correction importante :

> ⚠️ **Le constat du Round 2 sur `ardei-soft.com` ("plateforme obfusquée, effort substantiel") était FAUX.** Le vrai code legacy (`updateAgendaforEscale`/`updateAgendaforArdei`) montre un simple `file_get_contents()` sur `/{ville}/SenousritPGI?JAVOPP=GnAPIPlus&reqData={JSON}` — aucun JS à exécuter, l'API répond directement du JSON. Le blocage perçu (JS minifié `VEL-javi.js`) était une fausse piste : l'API sous-jacente n'a jamais nécessité ce JS. Corrigé et implémenté ci-dessous (`EscaleDriver`/`ArdeiDriver`, 90/90 événements importés en direct).

Pour chacune des 12 salles, le vrai code a été lu intégralement puis adapté à l'architecture `ScraperDriver` (résolution `Area`/`EventCategory` par `legacy_id` — voir plus bas — au lieu d'ids `t_areas`/`t_agenda_categories` codés en dur), **puis vérifié en conditions réelles** (`php artisan scrape:events --source={id}` contre le vrai site, pas seulement `Http::fake()`). Résultat de cette vérification live (25/08/2026) :

| Salle (cron legacy) | Driver | Source | Résultat live | Statut |
|---|---|---|---|---|
| Cite | `TheatreDeLaCiteDriver` | HTML theatre-cite.com | 30/30 (déjà en prod, Round 2) | ✅ |
| Zenith | `ZenithDriver` | API OpenAgenda | 94/94 créés | ✅ |
| Metropole | `MetropoleDriver` | API OpenAgenda | 300/300 créés (plafond API, voir limite ci-dessous) | ✅ |
| Garonne | `GaronneDriver` | HTML theatregaronne.com | 29/29 (0 ignoré après correctif) | ✅ |
| Leventdessignes | `LeventDesSignesDriver` | HTML leventdessignes.fr | 48 trouvés, 41 datables importés, 7 pages éditoriales ignorées (légitime) | ✅ |
| Odyssud | `OdyssudDriver` | HTML odyssud.com | 48/48 (0 ignoré après 2 correctifs) | ✅ |
| Escale | `EscaleDriver` | JSON ardei-soft.com/tournefeuille | 72/72 créés | ✅ |
| Ardei | `ArdeiDriver` | JSON ardei-soft.com/cornebarrieu | 18/18 créés | ✅ |
| Bijou | `BijouDriver` | JSON API le-bijou.soticket.net | 39/39 créés (jeton Bearer legacy toujours valide) | ✅ |
| GrandRond | `GrandRondDriver` | HTML grand-rond.org | 1 trouvé (le site lui-même n'a pas encore publié sa saison 2026-2027, "rendez-vous en septembre") | ✅ (comportement correct) |
| CasinoBarriere | `CasinoBarriereDriver` | HTML casinosbarriere.com | 133 trouvés, 133 ignorés | ⚠️ limite connue |
| Interprete | `InterpreteDriver` | HTML grandsinterpretes.fr | 0 trouvé | ❌ limite connue |

**Bugs legacy réels découverts et corrigés pendant la vérification live** (pas de simples ajustements de sélecteurs — de vraies erreurs de logique, dont certaines préexistaient probablement dans le code de production) :
- **Garonne/Leventdessignes — plage de dates à 2 nœuds sans mois sur le premier** : quand une date "du 07 au 15 octobre" est rendue par le site en 2 éléments (`"07"` puis `"15 Oct"`), le legacy traite chaque nœud comme une date complète indépendante → `$months['']` (clé vide) côté 1er nœud → date invalide. Corrigé : le mois du 2e nœud est réutilisé pour le 1er si celui-ci ne contient aucune lettre.
- **Translittération d'accents via `iconv('...//TRANSLIT...')`** : produit des apostrophes parasites sur ce serveur (`"Déc"` → `"D'ec"` au lieu de `"Dec"`), cassant la reconnaissance de mois abrégés accentués. Remplacé par une table de correspondance manuelle (`ParsesFrenchDates::ACCENT_MAP`), stable quel que soit l'environnement.
- **Odyssud — 3 `<span>` de date dont le 1er est vide** : le legacy suppose que 3 `<span>` = toujours une plage (jour début + "et" + jour+mois fin) ; le site laisse parfois le 1er `<span>` vide (pictogramme sans texte) quand il n'y a qu'UNE seule date → reconstruction absurde ("mois" sans jour). Corrigé : si le 1er `<span>` est vide, on traite comme une date unique.
- **Odyssud — absence de `<span>` imbriqué** : le site ne rend plus systématiquement le `<span>` que le legacy filtrait (`.duration-day span`) ; repli ajouté sur le texte direct de `.duration-day`.
- **OpenAgenda (Zenith/Metropole) — paramètres d'URL obsolètes** : le legacy appelle l'API avec `size=500`, qui renvoie désormais une erreur 400 ("size must not exceed 300", l'API a durci sa limite depuis l'écriture du legacy) — plafonné à 300. Idem pour le domaine d'image codé en dur (`https://images.openagenda.com/`), remplacé par le champ `image.base` réellement renvoyé par l'API (`https://img.openagenda.com/main/`).
- **Ardei-Soft — accolades/guillemets non encodés dans l'URL** : le legacy interpole le JSON `reqData` brut dans l'URL (`?reqData={"APIFunction":...}`) ; curl en CLI tolère ces caractères non conformes à la RFC 3986, mais le client HTTP Guzzle (utilisé par Laravel `Http::`) échoue silencieusement à parser l'URL. Corrigé avec `rawurlencode()`.

**Limites connues, documentées plutôt que masquées (consigne client)** :
- **`CasinoBarriereDriver`** : casinosbarriere.com a migré vers un front Nuxt3/Vue3 depuis l'écriture du legacy. Les blocs de catégorie (`.CsnNationalShowsPreviewCategory`) et les cartes spectacle (`.CsnCardShowPortrait`, nouveau sélecteur réel) existent toujours, MAIS leur `<a>` englobant n'a plus d'attribut `href` server-side — navigation 100% client-side (JS). Le scraper détecte donc bien les 133 spectacles (`found`) mais ne peut atteindre aucune fiche détail (`skipped`). Piste non creusée : le payload Nuxt `/nos-spectacles/_payload.json` contient probablement les données mais dans un format `devalue` (pas du JSON standard) nécessitant un désérialiseur dédié.
- **`InterpreteDriver`** : grandsinterpretes.com a changé de domaine (`.com` → `.fr`), de schéma d'URL de saison (`/saison/2023-2024/` → `/saison2026-2027/`) ET de CMS complet (thème WordPress "EventChamp"/"The Events Calendar" — plus aucune trace de `.concert-title`/`.fake-link`/`.taviraj.date`). Driver non fonctionnel (`found=0`) ; reconstruction complète des sélecteurs nécessaire contre le nouveau CMS, hors budget de cette phase.
- **`GrandRondDriver`** : fonctionnellement correct mais peu de contenu à date de vérification — le site annonce lui-même que sa saison 2026-2027 est "décalée" et sera publiée en détail début septembre.
- **`MetropoleDriver`/`ZenithDriver`** : l'API OpenAgenda plafonne désormais les réponses à 300 événements par requête (`aggsSizeLimit`/`size`) — au-delà, une pagination serait nécessaire (non implémentée), donc seuls les ~300 prochains événements de l'agenda "Toulouse Métropole" sont couverts par exécution (Zenith, avec 94 événements réels, n'atteint pas cette limite).

**Correspondance `legacy_id` → tables migrées** — chaque driver résout sa salle et sa/ses catégorie(s) via les colonnes `areas.legacy_id`/`event_categories.legacy_id` (PAS des slugs ou ids codés en dur), pour rester correct même si les tables migrées évoluent. Exemple vérifié : `id_area=3` (legacy, Théâtre de la Cité) → `Area::where('legacy_id', 3)` → id migré `2`, slug `tnt-theatre-de-la-cite` (confirme que `TheatreDeLaCiteDriver`, construit au Round 2 par déduction, pointait déjà sur la bonne salle). Deux helpers réutilisables portent cette logique : `Concerns\ResolvesCategory` (résolution par `legacy_id` + correspondance approximative de libellé pour les cas de catégorisation dynamique du legacy comme `getCategIdByLikeLib()`) et `Concerns\FetchesHttp`/`Concerns\ParsesFrenchDates` (réseau et dates FR, partagés par tous les nouveaux drivers). Deux bases abstraites factorisent les 2 familles de sources API JSON identifiées : `Concerns\AbstractOpenAgendaDriver` (Zenith, Metropole) et `Concerns\AbstractArdeiSoftDriver` (Escale, Ardei).

Chaque nouvelle source suit le même moule (`ScraperDriver`, une classe par site, ajoutée au seeder `AgendaScraperSourcesSeeder`) — architecture confirmée scalable à 12 sources réelles sans changement structurel.

### Scraper cinéma — vérification en direct (25/08/2026)

Précédemment documenté "non re-vérifié en direct, accès réseau bloqué depuis ce sandbox". Ce n'est plus le cas : allocine.fr répond désormais (200) depuis cet environnement (pathe.fr reste bloqué en 403, mais `AllocineDriver` ne dépend pas de ce domaine — voir son docblock, le vrai mécanisme legacy scrape l'API JSON interne d'AlloCiné, pas les sites des exploitants). Au passage, deux corrections de configuration dev :
- `ScraperSourcesSeeder` n'avait jamais été exécuté dans cet environnement (1 seule source cinéma en base, un reliquat manuel `PatheGaumontDriver` — classe supprimée depuis la correction d'audit du 2026-08-24, voir plus haut — donc en échec systématique). Exécuté : 25 vraies sources AlloCiné créées.
- La ligne reliquat (`ScraperSource` id legacy pointant vers la classe supprimée) a été nettoyée en base dev.

`php artisan scrape:cinema` exécuté contre les 25 vraies sources : **25/25 réussies, aucun échec**, 397 séances trouvées (16 nouveaux films créés, 381 séances de films déjà migrés backfillées avec de vrais horaires/liens de réservation — cohérent avec les 17 304 films déjà présents via `migrate:cinema`). Seule "Jean Marais" renvoie 0 résultat (pas d'échec — la salle n'a simplement aucune séance actuellement programmée sur AlloCiné).

### Proposition d'événement par le public (brief §6)

Gap identifié au §0 comblé : `EventController::create()`/`store()` (`GET`/`POST /agenda/proposer`), même modèle de modération STRICTE et non contournable que `ListingController`/`ClassifiedController` (`status`/`source` toujours forcés à `pending`/`user_submitted` côté serveur, jamais de valeur envoyée par le visiteur acceptée — testé explicitement, y compris tentative d'injection de `status=published`). Le formulaire admin (`EventResource`) gérait déjà le statut `pending` (badge, filtre) avant même cette phase — rien à ajouter côté admin.

Particularité par rapport aux annonces/annuaire : le lieu (`area_id`, FK) n'est pas choisi dans une liste déroulante — la table `areas` compte plusieurs milliers de lignes (import legacy brut, voir plus haut), impraticable en `<select>`. Le visiteur tape le nom du lieu ; `Area::firstOrCreate(['name' => ...])` réutilise une salle existante du même nom ou en crée une nouvelle à la volée — elle aussi implicitement soumise à la modération : tant que l'admin n'a pas validé l'événement, il reste `pending` et invisible publiquement, que le lieu référencé soit déjà connu ou tout neuf. Tests : `tests/Feature/PublicEventSubmissionTest.php` (6 tests — rendu du formulaire, statut/source forcés, honeypot, réutilisation de lieu existant, cohérence des dates).

### Recherche géographique — annuaire (brief §5)

Gap identifié au §0 : investigation confirme que la base legacy (`toulouseweb_old.t_article`) **n'a jamais stocké de coordonnées lat/lng structurées** — aucune colonne géo dans le schéma source (vérifié en direct le 25/08/2026, `SHOW COLUMNS`). Les colonnes `lat`/`lng` du schéma migré (`listings`) existent mais sont vides à 100% (2978/2978). Une vraie recherche "autour de moi"/par rayon nécessiterait de géocoder ~2978 adresses via un service externe (Google Maps Geocoding, Nominatim/OSM...) — décision produit (fournisseur, budget/clé API le cas échéant) hors de ce qui peut être tranché unilatéralement ici.

En attendant cette décision, un filtre par VILLE est livré (`?city=` sur `/annuaire`) :
- `LegacyCleaner::postalAndCity()` (nouveau) extrait code postal + ville depuis `t_article.adresse` (texte libre, souvent truffé de HTML/liens/texte marketing côté legacy) via une regex sur le format français standard "{...} {5 chiffres} {Ville}" en fin de chaîne, après suppression du HTML. Casse normalisée (le legacy mélange `COLOMIERS`/`Colomiers`/`Plaisance du touch`/`Plaisance-du-touch`...).
- Best-effort assumé et documenté : **1155/2978 fiches (~39%)** obtiennent une ville exploitable après ré-exécution de `migrate:listings` (idempotente) contre la vraie base ; le reste des adresses ne se termine pas par un format reconnaissable (URLs, texte générique, adresses sans code postal, formats étrangers) — laissé `null` plutôt que deviné.
- `ListingController` liste les 30 villes les plus représentées (parmi les fiches publiées) pour peupler le filtre ; `tests/Unit/LegacyCleanerTest.php` verrouille le comportement du parseur (cas positifs et négatifs réels observés en base) et `tests/Feature/PublicContentPagesTest.php::test_annuaire_city_filter` verrouille le filtre bout en bout.

### Performance — audit des requêtes réelles (brief §12, 25/08/2026)

Audit des index existants (`SHOW INDEX`) contre les WHERE/ORDER BY/GROUP BY réellement exécutés par les contrôleurs/widgets, plutôt qu'une passe générique. Couverture déjà bonne : `listings`/`events`/`classifieds`/`news` ont chacun un index composite `(status, ...)` posé dès la migration initiale, `redirects`/`missed_redirects` sont indexés sur leur clé de lookup (`from_path`/`path`, hot path — évalué à chaque requête non matchée par les routes), N+1 déjà globalement évités (`->with(...)` posé sur toutes les listes avec relations : `Event::with(['area','categories'])`, `Listing::with('categories')`, `Classified::with('category')`...).

Deux trous réels trouvés et corrigés (migration `2026_08_25_110000_add_performance_indexes.php`) :
- **`listings.city`** : filtré par le nouveau `?city=` (recherche géographique, voir plus haut), sans index.
- **`click_events.created_at`** : `ClickTrackingService::totalCount()`/`totalsByType()`/`topEntities()` (widgets `ClicksOverview`/`ClicksByTypeChart`/`TopClickedEntities`, tous `$isLazy = false` — rendus immédiatement) filtrent **uniquement** par plage `created_at`, sans `entity_type`. L'index composite existant `(entity_type, entity_id, created_at)` ne peut pas servir ces requêtes (colonne de tri/range en 3e position, pas en tête) — sur la plus grosse table de la base (**2,78M lignes**, l'historique de clics migré), ces 3 requêtes tournaient en **full scan à chaque chargement du dashboard admin**. `EXPLAIN` avant/après : `type: ALL` (scan complet) → `type: range` + `Using index` (index-covering, ~92k lignes lues sur 2,78M pour une fenêtre de 30 jours) après ajout de l'index `(created_at, entity_type, entity_id)`.

Pas de cache applicatif ajouté à ce stade : les tables de référence interrogées à chaque page (catégories, zones...) sont petites (quelques dizaines de lignes, lookups déjà indexés) — mise en cache jugée prématurée (complexité + risque de péremption pour un gain non mesurable à ce volume). À reconsidérer si le volume de trafic réel révèle un besoin (ex. cache court sur les agrégations homepage).

---

## 14. Déploiement (brief §26/Phase 14)

Checklist opérationnelle complète : `README.md` § Déploiement (procédure copier-coller). Cette section documente le RAISONNEMENT derrière les choix — aucun déploiement réel n'a été effectué depuis cet environnement de développement (pas d'accès à un serveur de production/cPanel), la procédure est écrite pour être suivie telle quelle par qui a cet accès.

### Pourquoi un seul cron (pas de worker de queue permanent)

Le brief cible un hébergement type cPanel/mutualisé (cohérent avec le legacy, dont les crons étaient de simples `wget` — voir la liste de tâches cron réelle fournie par le client pour l'agenda, §13). Sur ce type d'hébergement, un processus `php artisan queue:work` permanent (supervisé par Supervisor/systemd) n'est généralement PAS disponible. Solution retenue (`routes/console.php`) : `Schedule::command('queue:work --stop-when-empty')->everyMinute()` — le scheduler Laravel (lui-même déclenché par la SEULE ligne de cron serveur requise, `* * * * * php artisan schedule:run`) lance un worker qui traite tout ce qu'il y a dans la file puis s'arrête proprement (`--stop-when-empty`), plutôt que de tourner indéfiniment. Latence de traitement de queue de l'ordre de la minute — largement suffisant ici (les jobs de cette application sont des tâches différées non urgentes, pas du temps réel).

### Pourquoi `APP_DEBUG=false` est non négociable

L'audit du legacy (§4) a trouvé des messages d'erreur PHP bruts exposés au public (stack traces, chemins serveur). `APP_DEBUG=true` en production reproduirait exactement cette faille côté Laravel (pages d'erreur Whoops complètes, y compris variables d'environnement). Documenté dans `.env.example` en tête de fichier pour que l'oubli soit difficile.

### Ordre des caches au déploiement

`config:cache` DOIT être exécuté après que `.env` soit définitivement configuré (il "fige" `.env` dans un fichier compilé — toute modification de `.env` après un `config:cache` sans le regénérer est silencieusement ignorée, piège classique Laravel). D'où l'ordre dans la procédure README : `.env` → `migrate` → caches, jamais l'inverse.

### Migrations : sûres pour un rollback de code

Toutes les migrations de ce projet sont additives (nouvelles tables/colonnes/index — voir par exemple `2026_08_25_110000_add_performance_indexes.php`, §12) ou correctives non-destructives (`2026_08_24_090500_drop_screenings_unique_constraint.php` retire une contrainte unique trop stricte découverte en cours de route, sans perte de données). Aucune migration ne supprime de colonne contenant des données réelles. Conséquence pratique : un rollback de CODE (revenir à un commit précédent) reste compatible avec un schéma DB plus récent dans l'immense majorité des cas — la checklist README ne demande donc pas systématiquement un rollback de schéma symétrique.

### Ce qui reste hors de portée de ce dépôt

- Provisioning serveur (PHP/MySQL/certificat SSL) — dépend de l'hébergeur choisi, non tranché ici.
- Sauvegardes base de données — responsabilité hébergeur/infra, pas applicative.
- Un vrai service de géocodage pour la recherche géographique par rayon (brief §5, voir §13) — décision produit (fournisseur, budget/clé API) à trancher par le client.
- Décodage du payload Nuxt de casinosbarriere.com et reconstruction complète du driver Les Grands Interprètes (nouveau CMS) — voir §13, limites connues du scraper agenda.

### Retours client sur l'admin et le front (26/08/2026)

Six demandes ponctuelles, traitées ensemble :

- **Logo header pas assez visible** : correction du commit précédent — le fond `bg-[#CC0000]` (classe Tailwind arbitraire) avait été ajouté SANS relancer `npm run build`, donc absent du CSS compilé réellement servi (`public/build/manifest.json` existait déjà, donc Laravel sert les assets compilés, pas le serveur de dev Vite). Un `style="background-color:#CC0000"` inline est ajouté en complément (garantit la couleur même si un futur changement de classe oublie à nouveau le rebuild), et `npm run build` relancé. **Leçon retenue : après TOUT changement de classe Tailwind, relancer `npm run build`** — sinon le changement n'a aucun effet visible tant que le cache de build n'est pas régénéré.
- **Icônes en file upload / glisser-déposer** (Amenity, Category, EventCategory — "comme la gestion des sliders") : les 3 champs `icon` (`TextInput` texte libre jusqu'ici) passent en `FileUpload::make('icon')->image()` (config identique à `SliderResource::make('image')`, Filament gère nativement le glisser-déposer). `Category`/`EventCategory` n'avaient pas d'accesseur `icon_url` (`ResolvesImageUrl`) contrairement à `Amenity` — ajouté pour cohérence (aucun affichage public de ces icônes n'existe encore côté front, seulement l'admin).
- **Retour sur le listing après sauvegarde + indicateur de chargement** : vérifié dans le code source Filament (`vendor/filament/filament/src/Resources/Pages/{Create,Edit}Record.php`) que NI `CreateRecord` (redirige vers `view` puis `edit`, jamais l'index tant qu'aucune de ces pages n'existe) NI `EditRecord` (`getRedirectUrl()` retourne `null` par défaut, reste sur la page) ne le font par défaut. Nouveau trait `App\Filament\Concerns\RedirectsToIndexAfterSave` (`getRedirectUrl()` → URL de l'index de la ressource), appliqué aux **40 pages Create/Edit des 20 ressources**, sans exception. L'indicateur de chargement, lui, est déjà natif à Filament (`wire:loading` Livewire sur tout bouton d'action) — rien à coder. Tests : `tests/Feature/AdminSaveBehaviorTest.php` (AmenityResource comme cas représentatif — le trait étant partagé, dupliquer le test sur les 20 ressources serait redondant ; `AdminPanelSmokeTest` couvre déjà que les 20 continuent de fonctionner).
- **Espace mort entre "Annuaire" et son sous-menu** : le sous-menu utilisait `mt-1` (margin externe) pour le décalage visuel sous le bouton — cette marge n'appartient ni au bouton ni au sous-menu, donc `@mouseleave` sur le conteneur parent se déclenchait dès que la souris traversait cet espace, fermant le menu avant qu'il soit atteignable. Corrigé en déplaçant le décalage en `pt-1` (padding) À L'INTÉRIEUR d'un conteneur positionné juste sous le bouton (`top-full`) — le padding fait partie de la boîte de cet élément, donc le survol y reste "dans" un descendant du parent, pas de trou.
- **`target="_blank"` sur tous les liens externes** : déjà en place pour les sliders/réseaux sociaux/sites partenaires/site web annuaire (référence). Deux vrais oublis trouvés et corrigés : le lien de réservation d'un événement (`agenda/show.blade.php`, `Event::booking_url`) et le bouton "Réserver" d'une fiche annuaire (`annuaire/show.blade.php`, `Listing::reservation_url`). Audit complet des `<a href>`/`<x-ui.button>` du projet fait pour ne rien manquer — le reste pointe soit vers des routes internes (pas de `target="_blank"` à ajouter), soit n'est pas encore rendu comme lien cliquable (`ScreeningTime::booking_url`, `Listing::click_collect_url` : présents en base, scrapés/saisissables, mais aucune vue ne les affiche encore comme lien — hors scope de cette demande, à traiter si un besoin réel est signalé). Tests dédiés dans `tests/Feature/PublicContentPagesTest.php`.

Suite complète : 161 tests / 448 assertions, tous verts.

### Icône de catégorie et lightbox photos annuaire (27/08/2026, demande client)

- **Icônes de catégorie sur la homepage** (`home.blade.php`, section "Catégories populaires") : utilise désormais `$category->icon_url` quand une icône a été uploadée dans l'admin (`CategoryResource`, voir plus haut), avec repli sur le SVG générique par défaut sinon — auparavant la même icône générique s'affichait pour TOUTES les catégories, sans distinction.
- **Lightbox photos annuaire** (`annuaire/show.blade.php`, section "Photos") : cliquer une miniature ouvrait auparavant la photo dans un nouvel onglet (`target="_blank"`) — remplacé par un aperçu grand format en overlay (Alpine.js, cohérent avec le reste du site — pas de nouvelle dépendance JS), navigation chevron gauche/droite entre toutes les photos de la galerie, fermeture par bouton dédié ou clic sur le fond de l'overlay, navigation clavier (flèches, Échap). Tableau des URLs de photos injecté via `@js()` (échappement HTML sûr pour un attribut Alpine `x-data`).

Tests : `tests/Feature/HomepageTest.php::test_homepage_category_uses_admin_icon_when_set`, `tests/Feature/PublicContentPagesTest.php::test_annuaire_show_gallery_renders_lightbox_with_all_photos` (upload de vraies images factices via `Storage::fake('public')` + `UploadedFile::fake()`, pas une simple assertion de texte).

Rappel (voir section précédente) : `npm run build` relancé après ces changements de classes Tailwind (`max-h-[85vh]`, `max-w-[90vw]`, `bg-white/10`...), vérifié présent dans le CSS compilé avant de conclure.

### Retours client sur l'admin (01/09/2026)

- **Slugs auto-générés partout** : `AreaResource`, `CinemaResource`, `ClassifiedCategoryResource`, `MovieResource`, `NewsCategoryResource` n'avaient pas le pattern déjà utilisé ailleurs (`Category`/`Event`/`Listing`/`News`/`Page`/`Classified`/`EventCategory` : `->live(onBlur: true)->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug($state)))` sur le champ nom/titre) — appliqué aux 5 manquants pour cohérence totale. Le slug reste modifiable manuellement après génération automatique (pas de verrou).
- **Tri croissant/décroissant sur toutes les colonnes de tableau, date de création visible par défaut** : audit systématique des 21 ressources — `created_at` était présent partout mais caché par défaut (`toggleable(isToggledHiddenByDefault: true)`), retiré (`toggleable()` simple : reste masquable manuellement, juste visible par défaut). Colonnes texte sans `->sortable()` corrigées (recherche/tri ne sont pas la même chose côté Filament — `->searchable()` seul ne rend PAS triable). Cas particuliers vérifiés par un test dédié plutôt que supposés fonctionnels (`tests/Feature/AdminTableSortingTest.php`) : tri sur une colonne relationnelle `belongsTo` (`category.name`), tri sur un agrégat `->counts('users')` (RoleResource) — les deux fonctionnent, Filament les gère nativement. Tri non ajouté sur les colonnes relationnelles `belongsToMany` (`categories.name`, `roles.name`) : trier une liste à plusieurs valeurs par ligne n'a pas de sens univoque, laissé volontairement de côté plutôt que forcé.
- **"Référence externe (dédup scraper)"** (`EventResource`, champ `external_ref`) : identifiant unique côté site source (ex. dernier segment de l'URL de la fiche événement) utilisé par les scrapers agenda (`scrape:events`) pour reconnaître un événement déjà importé et le mettre à jour plutôt que le dupliquer à chaque exécution (voir `TheatreDeLaCiteDriver` et les 11 autres drivers, §13 plus haut) — sans objet pour un événement saisi à la main, où le champ reste vide. Un texte d'aide a été ajouté directement dans le formulaire admin pour que la question ne se repose plus.
- **"Le mois précédent du calendrier ne fonctionne pas"** (`/agenda?view=calendar`) : investigation approfondie (navigation directe par URL sur plusieurs mois consécutifs, inspection du HTML généré, vérification des en-têtes de cache, horloge serveur) — **aucun défaut fonctionnel reproduit** : le calcul `subMonthNoOverflow()`/`addMonthNoOverflow()` et la construction d'URL (`fullUrlWithQuery(['month' => ..., 'date' => null])`, qui supprime bien `date` du querystring — `http_build_query` omet les valeurs `null`) se sont révélés corrects dans tous les cas testés, y compris 2 clics "précédent" consécutifs. Piste identifiée : l'horloge système de cet environnement de développement accusait un décalage de quelques heures par rapport à la date de référence au moment du test (confirmé `date -u` vs `php -r 'echo gmdate(...)'`), ce qui peut avoir donné l'impression d'un mois "faux" sans être un bug de code. Comportement verrouillé par un test déterministe à date figée (`tests/Feature/PublicContentPagesTest.php::test_agenda_calendar_previous_month_navigation`) pour détecter une vraie régression future — à rouvrir si le problème persiste, avec des détails de reproduction plus précis (navigateur, la page ne change pas du tout vs affiche le mauvais mois).

Suite complète : 168 tests / 476 assertions, tous verts (`AdminTableSortingTest.php` : 4 tests ; `test_agenda_calendar_previous_month_navigation` dans `PublicContentPagesTest.php`).

### Bouton "Scraper" manuel par salle (01/09/2026, demande client)

`CinemaResource` (listing admin `/admin/cinemas`) : bouton d'action par ligne qui lance immédiatement le scraper AlloCiné de CETTE salle (pas besoin d'attendre le cron quotidien 5h). Colonne "Dernier scraping" ajoutée en complément (relatif, ex. "il y a 2 heures").

- **Orchestration** : extraite dans `App\Services\Scraping\ScraperRunner` (cycle de vie `ScraperRun`, `last_run_at`/`last_status`) — jusqu'ici dupliquée à l'identique dans `ScrapeCinema` ET `ScrapeEvents`. Les deux commandes ET ce bouton appellent désormais le même service : un lancement manuel depuis l'admin produit exactement le même suivi (`scraper_runs`) qu'un lancement cron, pas un mécanisme parallèle divergent.
- **Résolution de la source** : `ScraperSource::where('type','cinema')->where('config->cinema_id', $cinema->id)` (requête JSON native MySQL/Eloquent) — bouton masqué (`->visible()`) pour les 3 salles sans source (UGC Toulouse, Le Mermoz, Espace des Nouveautés, voir `ScraperSourcesSeeder`).
- **Loader** : natif à Filament (état de chargement Livewire du bouton pendant l'exécution PHP synchrone) — aucun code supplémentaire nécessaire, comme déjà noté pour les boutons de sauvegarde.
- **Résultat** : notification Filament (succès avec le détail trouvés/créés/mis à jour/ignorés, ou échec avec le message d'erreur) — pas de rechargement de page nécessaire pour voir le résultat.
- Tests : `tests/Feature/CinemaScrapeButtonTest.php` (le scraping ne cible QUE la salle cliquée — vérifié par une assertion négative sur l'appel réseau de l'autre salle —, bouton masqué sans source, notification d'échec propre en cas d'erreur upstream).

### Bug réel trouvé et corrigé — aucune séance affichée malgré un scraping réussi (01/09/2026)

Signalé par le client avec une capture de la vraie prod (`toulouseweb.com/cinema/cgr-blagnac`, séances réelles visibles ce jour) contre le comportement local : scraping lancé manuellement pour CGR Blagnac (25 films trouvés, 2 créés, 23 mis à jour — un vrai succès, confirmé par `ScraperRun`), mais **zéro séance affichée** sur `/cinema/salles/cgr-blagnac` en local.

**Cause identifiée** (`CinemaController::currentlyValid()`) : `screenings.start_date`/`end_date` sont des colonnes `DATE` pures, pas `DATETIME` (migration `adjust_cinema_schedule_columns` — un choix déjà pris en connaissance de cause pour dédoublonner par semaine de programmation, voir `AllocineDriver::currentProgrammingWeek()`). Le filtre comparait `end_date >= now()` : MySQL normalise `end_date` à minuit (`'2026-09-01 00:00:00'`) mais `now()` porte l'heure complète (`'2026-09-01 19:13:54'`) — dès la première seconde après minuit, le DERNIER jour de la fenêtre de programmation (typiquement "aujourd'hui", vu que le cron tourne chaque matin) était donc déjà exclu. **En pratique, quasi INSTANTANÉMENT après chaque scraping.**

**Vérification en direct contre la vraie base MySQL** (pas seulement en théorie) : requête reproduisant le bug → 0 séance "actuellement valide" pour CGR Blagnac malgré 2 605 lignes `screenings`/44 874 `screening_times` réellement en base ; même requête avec le correctif → 29 séances valides, confirmées visibles sur `/cinema/salles/cgr-blagnac` et `/cinema` en HTTP réel.

**Fix** : comparer à une chaîne `'Y-m-d'` nue (`now()->toDateString()`), pas un objet `Carbon`/une chaîne datetime complète — un `Carbon` lié en paramètre de requête se sérialise en `'Y-m-d H:i:s'`, que MySQL coerce silencieusement (colonne `DATE`, d'où le bug resté invisible lors d'un test manuel superficiel côté MySQL) mais que SQLite (moteur de test) compare en texte BRUT (`'2026-09-01' < '2026-09-01 00:00:00'` lexicographiquement) — une chaîne date nue élimine l'ambiguïté sur les deux moteurs, et confirmé par confrontation avec le vrai `old/backEnd/.../CinemaController.php` legacy : celui-ci compare TOUJOURS des chaînes `'Y-m-d'` explicites (`$datedeb`/`$datefin`), jamais `NOW()` — la refonte s'aligne donc aussi sur ce que faisait réellement le legacy.

**Portée du bug** : touchait TOUTES les salles (25 sources actives), pas seulement CGR Blagnac — `currentlyValid()` est le filtre partagé par `/cinema` (index), `/cinema/films/{slug}` (séances par film) et `/cinema/salles/{slug}` (séances par salle). Recherche exhaustive des comparaisons `now()` dans le reste du code (`ClassifiedController`, `Event`, `Slider`) : aucune autre colonne `DATE` pure comparée à un `now()` complet — les autres (`expires_at`, `start_date`/`end_date` d'Event, `starts_at`/`ends_at`) sont des colonnes `datetime`/`timestamp`, non affectées par ce bug précis.

Test de régression : `tests/Feature/PublicContentPagesTest.php::test_cinema_screening_ending_today_remains_visible_all_day` (date figée en fin de journée, 23h, pour reproduire exactement le scénario qui plantait — sans date figée le bug ne se manifeste pas forcément selon l'heure d'exécution du test).

### Horaires cliquables vers la réservation — "2e scraping" legacy (01/09/2026, demande client)

Demande client : *"il y a une deuxième scraping des cinémas pour /old/ qui permet de rendre les heures cliquables et qui envoi vers le site original du scraping avec le bon horaire et film pour une réservation. Il faut remettre en place cela."*

**Analyse du mécanisme legacy** (`old/backEnd/app/Http/Controllers/CinemaController.php`, ~lignes 1466-1745) : `autoUpdateCinemaAllocineLiens`/`autoUpdateCinemaAllocineLiens2` sont un DEUXIÈME passage, différé, qui rejoue l'API AlloCiné après le scraping principal pour backfiller `lien_resa` (URL de réservation) sur `t_cine_proj_heures`, via une table de staging `t_scrapping_tmp` traitée par lots de 50. Sa logique de sélection d'URL : parmi `showtimes[].data.ticketing[]`, ne garder que les entrées `provider == "default"` (le vrai site de la salle, PAS `provider == "relay"` qui est un agrégateur de redirection AlloCiné), prendre `urls[0]`.

**Vérification** : cette logique de sélection est déjà répliquée EXACTEMENT, en un seul passage (pas de staging séparé), par `App\Services\Scraping\Cinema\AllocineDriver::extractBookingUrl()` — code déjà existant, non modifié ici, avec un docblock qui documente cette correspondance. Confirmé via `php artisan tinker` sur la vraie base MySQL de dev : les 44 874 lignes `screening_times` de CGR Blagnac (cinema_id=2) ont TOUTES `booking_url` renseigné (couverture 100 %). **Donc aucun bug côté scraper/backend** — le mécanisme "2e scraping" du legacy est déjà couvert, en mieux (un seul passage, pas de staging table à nettoyer).

**Le vrai écart identifié** : `resources/views/cinema/movie.blade.php` et `resources/views/cinema/salle.blade.php` affichaient chaque horaire comme un `<span>` texte simple — jamais cliquable, `booking_url` calculé et stocké en base mais jamais rendu. C'est ça qui manquait par rapport à la prod/au legacy.

**Fix appliqué** (les deux vues, boucle `@foreach ($screening->times as $time)`) : si `$time->booking_url` est renseigné, rendre un `<a href="{{ $time->booking_url }}" target="_blank" rel="noopener" data-track="screening_time:{id}:cinema_booking_click">` (convention `target="_blank"` déjà établie pour tous les liens externes) ; sinon conserver le `<span>` non cliquable d'origine (cas d'un horaire sans lien "default" retourné par AlloCiné pour cette séance précise — rare mais réel, pas une erreur).

Vérifié en direct : `curl http://localhost:8000/cinema/salles/cgr-blagnac` → 368 horaires avec `data-track="...cinema_booking_click"`, liens réels vers `achat.cgrcinemas.fr` etc. (`npm run build` rejoué pour les classes Tailwind `decoration-dotted`/`underline-offset-2`).

Test de régression : `tests/Feature/PublicContentPagesTest.php::test_cinema_screening_time_with_booking_url_is_clickable` — vérifie qu'un horaire AVEC `booking_url` produit un `<a href>` cliquable (attributs `target="_blank"`/`rel="noopener"`/`data-track` présents) sur `/cinema/films/{slug}` ET `/cinema/salles/{slug}`, et qu'un horaire SANS `booking_url` reste un `<span>` (pas de `data-track` pour son id).

### ⚠️ Bug réel trouvé et corrigé — jour affiché décalé d'un jour par rapport au vrai lien de réservation (02/09/2026, demande client)

Signalé par le client juste après la mise en place des liens cliquables ci-dessus : *"un petit souci de cohérence de lien par heure dans le front (ou dans le scraping) car les séances du jour J sur le front de la refonte est J+1 dans le site original destinataire du scraping."*

**Cause identifiée** : `screening_times.weekday` (0-6) suit STRICTEMENT la convention du legacy — confirmé en relisant `old/backEnd/app/Http/Controllers/CinemaController.php` : `$jour = $date->format('w')` (PHP `date('w')` = **0 Dimanche … 6 Samedi**), et la requête SQL historique de `getFilmsByCineSlug` mappe explicitement `jour = 0 as sunday`, `jour = 1 as monday`, …, `jour = 6 as saturday`. Cette même échelle (Carbon `->dayOfWeek`, identique à PHP `date('w')`) est ce que stocke `AllocineDriver::syncShowtimes()`, et c'est aussi ce qu'utilise, correctement, `ScreeningsRelationManager::WEEKDAYS` dans l'admin (`0 => 'Dimanche', 1 => 'Lundi', …`, avec un commentaire qui le documente explicitement).

Mais les deux vues PUBLIQUES (`resources/views/cinema/movie.blade.php` et `cinema/salle.blade.php`) utilisaient un tableau `['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']` indexé 0-6 — une convention Lundi=0 jamais confirmée (l'ancien commentaire du code le qualifiait lui-même d'"hypothèse à confirmer"). Résultat : chaque horaire était affiché avec le libellé du jour SUIVANT celui réellement scrapé (un horaire réellement scrapé un dimanche — `weekday=0`, `booking_url` pointant vers le vrai lien du dimanche sur le site d'origine — s'affichait "Lundi" sur notre front). Le lien de réservation lui-même était toujours correct (il pointe vers le jour réellement scrapé, voir section précédente) : c'est uniquement le LIBELLÉ du jour affiché à côté qui était incohérent avec la vraie date du lien — d'où l'impression, en cliquant, d'atterrir sur "le jour suivant" par rapport à ce qui était affiché.

**Fix** : alignement des deux vues publiques sur la convention unique, confirmée par le legacy et déjà utilisée côté admin : `$weekdays = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']`. Aucun changement côté scraper/base de données — la donnée stockée (`weekday`, `booking_url`) était déjà correcte, seul l'affichage du libellé était fautif.

Test de régression : `tests/Feature/PublicContentPagesTest.php::test_cinema_screening_time_weekday_label_matches_legacy_convention` — un horaire `weekday=0` doit afficher "Dimanche" (jamais "Lundi") et `weekday=1` doit afficher "Lundi" (jamais "Mardi"), sur `/cinema/films/{slug}` ET `/cinema/salles/{slug}`.

### Actualités-événements : nouveaux champs "informations pratiques" (03/09/2026, demande client)

Demande client : une actualité peut décrire un vrai événement (brocante, salon, animation...) et a besoin de ses propres dates de validité et informations pratiques, distinctes de la date de publication de l'ARTICLE.

**Champs ajoutés** (migration `add_event_fields_to_news_table`, `News` model) : `start_date`/`end_date` (colonnes `date` pures — demande explicite "juste les dates et non l'heure"), `schedule` (horaire, texte libre — ne pas confondre avec `events.schedule` qui est un JSON structuré, même nom de colonne mais sémantique différente entre les deux modèles), `address`, `price` (texte libre, comme `events.price`), `phone`, `email`, `website`, `youtube_url`.

**Visibilité front** (`News::scopePublished()`) : un article dont `end_date` est dépassée n'apparaît plus sur le site public (`/actualites`, `/actualites/{slug}`, miniatures homepage), quel que soit son statut admin — un article sans `end_date` (actu classique, pas d'événement) reste toujours visible normalement. Comparaison en chaîne `Y-m-d` nue (`now()->toDateString()`), **jamais** un `now()`/objet `Carbon` complet contre cette colonne `date` pure — piège déjà rencontré et documenté sur `CinemaController::currentlyValid()` (§ ci-dessus) : un test dédié (`test_news_ending_today_remains_visible_all_day`) verrouille qu'un événement se terminant aujourd'hui reste visible toute la journée.

**Affichage** (`actualites/show.blade.php`) : chaque champ n'apparaît dans la fiche détaillée que s'il a une valeur (`@if` individuel par champ, pattern déjà utilisé sur `agenda/show.blade.php`/`annuaire/show.blade.php`) — bloc "informations pratiques" (date, horaire, lieu, tarif, téléphone en `tel:`, email en `mailto:`, site web en `target="_blank"` avec `data-track`), puis un lecteur YouTube responsive (`<iframe>` 16:9) si `youtube_url` est renseigné. `News::youtubeEmbedUrl()` extrait l'identifiant de vidéo depuis plusieurs formats d'URL (`watch?v=`, `youtu.be/`, `/embed/`, `/shorts/`) par regex, et retourne `null` (pas d'erreur) si le lien saisi en admin est mal formé. JSON-LD schema.org `Event` ajouté en complément du `NewsArticle` existant, uniquement si `start_date` est renseignée.

**Miniature homepage** (`home.blade.php`) : affiche désormais `$news->event_date_range` (accesseur du modèle : "01 sept. 2026" ou "01 sept. 2026 → 15 sept. 2026" si les deux dates diffèrent) à la place de la date de publication, avec repli sur `published_at` si l'article n'a pas de dates d'événement (actu classique). Changement volontairement limité à la home (demande explicite du client) — `actualites/index.blade.php` garde `published_at` en meta.

**Admin** (`NewsResource`) : nouveaux champs regroupés dans une section repliable "Informations de l'événement", avec validation Filament (`->url()` sur site web/YouTube, `->tel()`/`->email()`, `->afterOrEqual('start_date')` sur la date de fin) et un texte d'aide rappelant que l'article disparaît du site public une fois la date de fin dépassée.

Tests : `tests/Feature/PublicFormsAndNewsTest.php` (visibilité selon `end_date`, rendu conditionnel de chaque champ, lien YouTube converti en iframe), `tests/Feature/HomepageTest.php::test_homepage_news_card_shows_event_date_range_when_set`, `tests/Feature/NewsResourceEventFieldsTest.php` (saisie/validation/édition des champs depuis l'admin Filament).

**Suppression de la limite de longueur sur `website`/`youtube_url`** (04/09/2026, demande client sur `/admin/news/create` : *"ne pas limiter la longueur du texte car il y a des liens très long"*) — même famille de bug que les URLs de billetterie déjà rencontrées (`events.booking_url`/`screening_times.booking_url`, voir migrations `widen_url_columns_phase5`/`widen_screening_time_booking_url`, paramètres UTM/tracking dépassant 255 caractères). Fix : migration `widen_news_url_columns` passe les deux colonnes en `text` (au lieu d'un simple `string(N)` élargi, pour vraiment supprimer la limite plutôt que la reculer, conformément à la demande explicite) + suppression du `->maxLength()` correspondant sur les deux champs Filament. Test de régression : `NewsResourceEventFieldsTest::test_very_long_website_and_youtube_urls_are_accepted_without_truncation` (lien de plus de 255 caractères accepté et conservé intégralement en base).

### Colonnes de tableau admin redimensionnables (06/09/2026, demande client)

Demande client (`/admin/news`) : *"réduire un peu la colonne titre. Ou mieux, permettre d'étirer/réduire la largeur des colonnes."* — préférence explicite pour la solution générique plutôt qu'une largeur fixe sur une seule colonne d'une seule ressource.

Filament 3.3 (vérifié dans `vendor/filament/tables`) n'a pas de fonction native de redimensionnement de colonnes. Implémenté en JS vanilla (`resources/views/filament/resizable-columns.blade.php`, aucune dépendance ajoutée), injecté une seule fois pour tout le panneau via un render hook (`PanelsRenderHook::BODY_END` dans `AdminPanelProvider`) plutôt que dans chaque Resource — couvre les ~20 ressources d'un coup.

Fonctionnement : une poignée de redimensionnement (`::after`-like, `<span>` absolument positionné) est ajoutée sur chaque `<th>` ; au glisser, la largeur est appliquée à la fois au `<th>` et aux `<td>` correspondants (même index de colonne) de toutes les lignes, et le tableau bascule en `table-layout: fixed`. Persistée dans `localStorage` (clé = chemin de la page + index de colonne), donc par navigateur/admin — pas partagée entre postes, cohérent avec les autres préférences d'affichage Filament (colonnes masquées via `->toggleable()`, qui fonctionnent pareil).

Piège évité : les tableaux Filament sont rendus par Livewire et se re-rendent (remplacement du DOM) au tri/filtre/pagination sans rechargement de page complet — un simple listener au chargement de la page aurait perdu les poignées et les largeurs après la première interaction. Un `MutationObserver` sur `document.documentElement` ré-applique donc les largeurs sauvegardées et ré-attache les poignées à chaque mutation du DOM (avec un garde `dataset.twWidth` pour rester idempotent et ne pas re-déclencher indéfiniment l'observateur lui-même).

Test : `AdminPanelSmokeTest::test_resizable_columns_script_is_present_on_admin_pages` — vérifie que le script est bien servi sur une page admin représentative (le hook étant partagé par toutes les ressources, un test par ressource serait redondant).

## 17. Purge automatique du cache Cloudflare (06/09/2026, demande client)

Demande client : *"en prod, il y a Cloudflare et pour chaque ajout/modif/suppression, ça doit supprimer les caches Cloudflare des pages correspondantes (aussi pour la homepage si par exemple il s'agit d'un article qui s'affiche dans la page d'accueil)."*

**Analyse du mécanisme legacy** (`old/backEnd/app/Http/Controllers/SharedController.php` lignes ~1149-1430) : un mécanisme de purge Cloudflare existe déjà, avec les identifiants Cloudflare (Zone ID, email, clé API) codés EN CLAIR dans le code source. Vérification faite de sa couverture réelle : `purgeEntityCache($entity)` n'est réellement câblée que sur **3 entités** — `slider` (`SharedController.php`), `news` (`NewsController.php`), `agenda` (`AgendaController.php`). L'annuaire (`t_article`), le cinéma et les annonces classées **n'ont jamais eu** de purge Cloudflare, même dans le legacy. Un appel générique dans `BOController::create()` (lignes 279-289) qui aurait pu couvrir plus de cas est présent mais **commenté/mort** — jamais exécuté en production. Donc : la demande du client va au-delà de ce que faisait même l'ancien système, pas une simple reproduction.

**Implémentation** (`App\Services\Cache\CloudflareCachePurger`, `App\Contracts\HasCloudflarePurgeUrls`, `App\Observers\CloudflarePurgeObserver`) :

- **Tous** les modèles de contenu public sont couverts : `News`, `Event`, `Listing`, `Classified`, `Movie`, `Cinema`, `Slider`, `Page` — chacun implémente `cloudflarePurgeUrls(): array` retournant les URLs front impactées par son état actuel (sa propre fiche, l'index de sa section, ses catégories, et **la home** quand c'est pertinent — `News`/`Event`/`Listing`/`Classified`/`Movie` y apparaissent tous potentiellement, voir `HomeController::index()`).
- **Authentification par jeton API** (`Authorization: Bearer`), pas le couple email + clé API globale du legacy (identifiants en clair, mécanisme moins sûr et déprécié côté Cloudflare) — un jeton scopé "Zone.Cache Purge" limite les dégâts en cas de fuite. Config : `CLOUDFLARE_CACHE_PURGE_ENABLED` (défaut `false`, **jamais** activé en local/dev/test — forcé explicitement dans `phpunit.xml` en plus du défaut, défense en profondeur), `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_API_TOKEN`.
- **Découplage accumulation/envoi, essentiel** : un `Observer` générique (un seul, partagé par les 8 modèles) accumule les URLs en mémoire (`CloudflareCachePurger::queue()`, singleton, dédoublonnage par clé) à chaque sauvegarde/suppression — **aucun appel HTTP immédiat**. L'envoi réel (`flush()`, par lots de 30 URLs — limite de l'API Cloudflare `purge_cache` par liste de fichiers) n'a lieu qu'**une seule fois**, à la toute fin du processus (`app()->terminating()`, enregistré dans `AppServiceProvider`). Sans ce découplage, `scrape:cinema`/`scrape:events` (des centaines de `Movie`/`Event` sauvegardés en une seule exécution quotidienne) déclencheraient autant d'appels HTTP à l'API Cloudflare qu'il y a de lignes touchées — au-delà des quotas de purge de la plupart des plans Cloudflare. `app()->terminating()` se déclenche aussi bien en fin de requête HTTP (une sauvegarde admin Filament) qu'en fin de commande artisan (un scraping cron) : le même mécanisme couvre les deux cas sans code spécifique.
- **Changement de slug** : purge à la fois la nouvelle ET l'ancienne URL (sinon Cloudflare continuerait à servir la version en cache de l'ancienne URL jusqu'à expiration du TTL, alors que l'appli renvoie déjà un 404 dessus). Piège Eloquent rencontré et corrigé pendant l'implémentation : `getOriginal('slug')` ne convient PAS dans un listener `saved` — à ce stade, `performUpdate()` a déjà exécuté `syncChanges()` mais surtout, en pratique (vérifié empiriquement), la valeur retournée est déjà la NOUVELLE, pas l'ancienne. L'accesseur correct pour "la valeur juste avant CETTE sauvegarde" est `getPrevious()['slug']` — ajouté par Eloquent précisément pour ce besoin.
- **Piège de relation mise en cache** rencontré et corrigé (`Event::categories`, `Listing::categories`, `Slider::placements`) : `$this->categories` (accesseur magique) charge et **met en cache** la collection sur l'instance dès le premier accès. Si l'observer accède à cette collection lors du `saved()` du `create()` initial (relation encore vide), puis qu'on `attach()`/`create()` sur la relation et qu'on resauvegarde la MÊME instance en mémoire, l'accesseur magique renvoie la collection VIDE mise en cache, pas l'état réel en base — découvert via un test qui échouait (`Http::assertSent` ne trouvait pas les URLs de catégorie attendues). Fix : `$this->categories()->get()` (requête fraîche à chaque appel) au lieu de `$this->categories`.
- Ne lève jamais d'exception : une purge manquée (Cloudflare indisponible, jeton expiré...) est un défaut de fraîcheur temporaire (TTL du cache), jamais une raison de faire échouer une sauvegarde admin ou un cron de scraping — erreurs journalisées (`Log::channel('single')`), pas remontées.
- **Limitation assumée** : les pages `seo-menu-{slug}` (métadonnées SEO migrées du legacy via `migrate:seo`, une par ligne `t_seo`) ne sont actuellement rattachées à aucune route publique rendue (seule `seo-menu-annuaire` l'est, via `ListingController`) — `Page::cloudflarePurgeUrls()` ne mappe donc que les 3 clés effectivement lues (`home`, `contact`, `seo-menu-annuaire`), les autres ne purgent rien (pas de page cible réelle à purger).

**Tests** : `tests/Feature/CloudflareCachePurgeTest.php` (14 tests) — désactivé par défaut même avec zone/token configurés, activé mais mal configuré (skip propre), URLs correctes par modèle (home + index + fiche + catégories), changement de slug purge les deux URLs, une création ne tente jamais de purger une "ancienne" URL inexistante, suppression, lots de plus de 30 URLs envoyés en plusieurs appels, et une preuve de bout en bout via une VRAIE requête HTTP publique (`POST /annuaire/deposer`) confirmant que `app()->terminating()` déclenche bien le flush automatiquement (les autres tests appellent `flush()` manuellement, car `Livewire::test()` ne traverse pas le cycle complet requête → `Kernel::terminate()`).

**Ce qui reste à faire pour activer en production** : créer un jeton API Cloudflare (dashboard Cloudflare → Mes profils → Jetons API → modèle "Modifier le cache Cloudflare", restreint à la zone `toulouseweb.com`), renseigner `CLOUDFLARE_ZONE_ID`/`CLOUDFLARE_API_TOKEN`/`CLOUDFLARE_CACHE_PURGE_ENABLED=true` dans le `.env` de production. Rien d'autre à configurer côté serveur — le mécanisme est déjà entièrement câblé dans le code applicatif (pas un script cron séparé).

## 18. Audit des scrapers agenda vs. legacy (06/09/2026, demande client)

Demande client : *"vérifie bien les scrapings agendas que tout est bien fonctionnel par rapport à /old/."* Ré-audit complet, driver par driver, des 12 sources réelles (`app/Services/Scraping/Agenda/*Driver.php`) contre les méthodes `updateAgendafor*`/`getIdTheater*` correspondantes du contrôleur legacy (`old/backEnd/app/Http/Controllers/AgendaController.php`, ~4800 lignes) — sans modifier de code (audit de lecture seule), sans exécuter `scrape:events` (appels réseau réels non pertinents en sandbox).

**Résultat global initial : 4 OK, 6 problème mineur, 2 problème majeur.** Les 6 problèmes mineurs ont été corrigés le 06/09/2026 (accord client) — voir tableau mis à jour et section "Correctifs appliqués" ci-dessous. Les 2 majeurs restent inchangés, non entrepris (nécessitent une reconstruction substantielle, hors périmètre de cette correction ciblée) :

| Driver | Verdict | Constat |
|---|---|---|
| TheatreDeLaCiteDriver | ✅ OK | Fidèle, live-vérifié 30/30 ; capture du tarif même améliorée vs. le `price=0` mort du legacy. |
| ZenithDriver | ✅ OK | Portage OpenAgenda correct (domaine image, plafond de taille) ; live-vérifié 94/94. |
| GrandRondDriver | ✅ OK | Seul driver à répliquer intégralement le champ `schedule` ; site en creux de saison au moment de l'audit (légitime, documenté). |
| LeventDesSignesDriver | ✅ OK | Fidèle champ pour champ, y compris le correctif de succession de mois documenté. |
| MetropoleDriver | ⚠️ Mineur (non corrigé) | Mapping de catégories (le plus complexe des 12) parfaitement répliqué, MAIS le plafond de 300 événements de l'API OpenAgenda fait perdre silencieusement des événements réels sur cette agenda volumineuse, sans signal distinctif dans `ScraperRun`. Non corrigé : nécessiterait d'implémenter la pagination de l'API OpenAgenda, hors périmètre de cette correction (capture de champs + catégorie). |
| GaronneDriver | ✅ Corrigé | `price`/`schedule` désormais capturés via le 2e appel HTTP vers la billetterie (`fetchTicketingInfo()`) ; repli de catégorie aligné sur le legacy (id 7/"Spectacles" via `categoryByLegacyId(7)`, plus un slug "théâtre" deviné). |
| OdyssudDriver | ✅ Corrigé | `schedule` désormais capturé (`.field--name-dates .date-field-item`, une entrée "jour: heure" par représentation). |
| EscaleDriver | ✅ Corrigé | `schedule` désormais capturé ("à partir de {heure de fin}", reproduit exactement du legacy — `dateF`, pas `dateD`). |
| ArdeiDriver | ✅ Corrigé | `schedule` désormais capturé ("HH:MM" zéro-complété depuis `dateD`, reproduit exactement du legacy). |
| BijouDriver | ✅ Corrigé | `schedule` désormais capturé, en tableau (une entrée par séance) plutôt que la chaîne " / "-concaténée du legacy — amélioration délibérée, plus exploitable côté front. |
| CasinoBarriereDriver | ❌ Majeur (inchangé) | Site migré en Nuxt3/Vue3 : 133 spectacles trouvés mais 133 `skipped`, aucune donnée de détail (date, prix, lien) importable. Piste de fix (déchiffrer le payload Nuxt `_payload.json`) documentée mais non tentée — travail non trivial. |
| InterpreteDriver | ❌ Majeur (inchangé) | CMS entièrement remplacé (domaine `.fr`, WordPress "The Events Calendar") : `found=0`. URL de saison mise à jour (fetch réussit désormais) mais sélecteurs jamais reconstruits contre le nouveau CMS. |

### Correctifs appliqués (06/09/2026, accord client)

**Capture du champ `schedule`** (Garonne, Odyssud, Escale, Ardei, Bijou) : chaque driver reproduit désormais EXACTEMENT la formule de son propre code legacy (vérifiée à nouveau ligne par ligne dans `old/backEnd/.../AgendaController.php` avant correctif) — pas une formule générique unique, chaque salle avait sa propre bizarrerie (Escale utilise `dateF` la date de FIN pour un horaire "à partir de", Ardei utilise `dateD` la date de DÉBUT zéro-complétée, Garonne/Odyssud viennent d'un second scraping HTML, Bijou d'un tableau de séances). Stocké en tableau (une entrée par horaire/séance) plutôt que la chaîne concaténée du legacy pour Bijou/Odyssud — cohérent avec le cast `array` d'`Event.schedule` et plus exploitable côté front qu'une chaîne plate, une amélioration délibérée documentée dans chaque driver concerné.

**Repli de catégorie Garonne** : remplacé `EventCategory::where('slug', 'theatre')` (deviné, jamais confirmé contre le legacy) par `categoryByLegacyId(7, 'divers')` — id 7 = "Spectacles", le VRAI repli du legacy (`if (!$categId || $categId == 0) $categId = 7;`, ligne ~667), déjà utilisé tel quel par EscaleDriver/BijouDriver. Config `fallback_category_slug` retirée du seeder (devenue inutile).

**Affichage front** : `Event.schedule` n'était affiché nulle part avant ce correctif (capturé en base mais invisible) — ajout d'une ligne "Horaires" sur `agenda/show.blade.php`, visible uniquement quand renseigné, suivant le même pattern conditionnel déjà utilisé pour "Tarif"/"Lieu".

**Tests** : chaque driver corrigé a une assertion dédiée sur `$event->schedule` (valeur exacte attendue selon SA propre formule) dans son fichier `tests/Feature/Agenda/*ScraperTest.php` ; Garonne a aussi un test dédié pour le 2e appel HTTP (prix+horaire) et un pour le repli de catégorie ; `PublicContentPagesTest::test_agenda_show_renders_schedule_when_present` verrouille l'affichage conditionnel côté front.

**Constat transversal (non corrigé, distinct des 6 problèmes ci-dessus, hors du périmètre convenu) — aucun des 12 drivers ne désactive un événement disparu de sa source** : 5 méthodes legacy (Zenith, Metropole, Garonne, Casino Barrière, Odyssud, via `flushAgenda()`/un balayage `NOT IN` sur les slugs) mettaient `status = 0` sur les événements qui disparaissaient de la source avant de réinsérer les événements actuels — ce qui gérait le cas d'un spectacle annulé/retiré AVANT sa date prévue. Aucun driver refonte ne reproduit ce mécanisme. Impact partiellement atténué par `Event::scopeUpcoming()` (filtre par date, cache automatiquement un événement une fois sa date passée) mais **pas** un événement annulé avant sa date, qui resterait visible indéfiniment sur ces 5 sources. Ce constat avait été présenté séparément des "6 problèmes mineurs" à corriger — reste ouvert, disponible comme chantier distinct si le client le souhaite.

**Constat transversal nouveau — aucun des 12 drivers ne désactive un événement disparu de sa source** : 5 méthodes legacy (Zenith, Metropole, Garonne, Casino Barrière, Odyssud, via `flushAgenda()`/un balayage `NOT IN` sur les slugs) mettaient `status = 0` sur les événements qui disparaissaient de la source avant de réinsérer les événements actuels — ce qui gérait le cas d'un spectacle annulé/retiré AVANT sa date prévue. Aucun driver refonte ne reproduit ce mécanisme. Impact partiellement atténué par `Event::scopeUpcoming()` (filtre par date, cache automatiquement un événement une fois sa date passée) mais **pas** un événement annulé avant sa date, qui resterait visible indéfiniment sur ces 5 sources. Non documenté jusqu'ici comme limitation.

**Constat transversal (déjà connu, confirmé hérité, pas introduit)** : `events.external_ref` est indexé mais pas contraint unique en base, et aucun driver ne scope son `updateOrCreate` par `area_id` — deux venues dont le dédoublonnage collide (peu probable, chaque venue slugifie ses propres titres de spectacle) pourraient s'écraser mutuellement. Le schéma legacy avait la même faiblesse (`t_agendas.slug` non plus unique).

**Câblage cron/config vérifié sain** : `ScrapeEvents::handle()` isole bien les échecs par source (un `\Throwable` par source ne bloque pas les 11 autres) ; les 12 classes driver correspondent exactement aux 12 lignes de `AgendaScraperSourcesSeeder` (aucun orphelin dans un sens ou l'autre) ; `Schedule::command('scrape:events')->withoutOverlapping()` élimine le risque de double exécution concurrente qui aggraverait le risque de collision `external_ref` ci-dessus ; pas de bouton "scraper manuellement" pour l'agenda (contrairement au cinéma), donc pas de risque de run manuel + cron simultanés.

**Couverture de tests vérifiée** : les 12 drivers ont chacun un test dédié (`tests/Feature/Agenda/*ScraperTest.php`, 1 à 2 tests chacun) + `ScrapeEventsTest.php` pour le comportement d'orchestration générique (isolation des échecs, source inactive ignorée). Aucun test n'exerce le champ `schedule` pour les 5 drivers concernés — c'est exactement pourquoi la perte silencieuse n'avait pas été détectée par la suite existante.

**Suite donnée** : les 6 problèmes mineurs (capture de `schedule` sur 5 drivers + catégorie de repli Garonne) ont été corrigés le 06/09/2026, sur accord explicite du client suite à la présentation de cet audit — voir "Correctifs appliqués" ci-dessus. La désactivation des événements disparus (constat distinct, non inclus dans ces 6 problèmes) et les 2 problèmes majeurs (Casino Barrière, Les Grands Interprètes) restent des chantiers ouverts, non entrepris.

## 19. Inventaire cron serveur (06/09/2026, demande client)

Demande client : *"retourne-moi tous les API et commandes à mettre en place dans des tâches cron du serveur."*

**Différence d'architecture majeure avec le legacy** : l'ancien système n'a PAS de tâche cron interne — la planification est externe, au niveau de l'hébergeur, qui appelle des **URLs API HTTP** (`wget https://toulouseweb.com/backend/public/api/autoUpdateCinemaAllocine/{id}`, une entrée cron par salle/venue) sans dédoublonnage centralisé. La refonte utilise le planificateur natif de Laravel (`routes/console.php`, voir §11) : **une seule entrée cron serveur est nécessaire**, qui exécute des commandes artisan (processus interne PHP CLI), pas des appels HTTP vers des routes publiques. Il n'y a donc **aucune "API" à exposer ni à sécuriser pour le cron** dans la refonte — une simplification et une réduction de surface d'attaque volontaires par rapport au legacy (pas de route `/api/...` déclenchant un scraping accessible sans authentification depuis l'extérieur).

**1. Entrée cron serveur unique à configurer :**

```
* * * * * cd /chemin/vers/toulouseweb_refonte && php artisan schedule:run >> /dev/null 2>&1
```

**2. Ce que cette unique entrée déclenche en interne** (planifié dans `routes/console.php`, rien d'autre à ajouter côté serveur) :

| Commande | Rôle | Fréquence |
|---|---|---|
| `queue:work --stop-when-empty` | Traite la file d'attente (emails, traitement d'images) | Chaque minute |
| `sitemap:generate` | Régénère `sitemap.xml` (fichier statique) | Quotidien |
| `scrape:cinema` | Scraping AlloCiné, 24-25 salles actives (films, séances, horaires, liens de réservation) | Quotidien à 5h00 |
| `scrape:events` | Scraping agenda, 12 sources réelles (voir §18 pour l'état détaillé de chacune) | Quotidien à 5h30 |
| `content:mark-expired` | Statut `expired` sur événements/annonces dont la date est dépassée | Quotidien à 4h30 |
| `redirects:audit` | Repère les 404 fréquentes sans redirection associée (affichage console, pas d'action automatique) | Hebdomadaire |
| `App\Observers\CloudflarePurgeObserver` (pas une commande — déclenché automatiquement) | Purge Cloudflare des pages impactées par tout ajout/modif/suppression de contenu (voir §17) | À chaque sauvegarde admin ET à chaque exécution de `scrape:cinema`/`scrape:events` (un seul lot d'appels HTTP en fin de commande) |
| `App\Observers\GoogleIndexingObserver` (pas une commande — déclenché automatiquement) | Demande d'indexation Google Search Console à chaque ajout/modif/suppression d'actualité/événement/fiche annuaire/annonce (voir §20) | À chaque sauvegarde admin (PAS pendant `scrape:cinema`/`scrape:events`, volontairement exclu — voir §20) |

**3. Commandes de récupération ponctuelle — PAS de cron, à lancer manuellement au besoin** (contre une source de données précise, une seule fois ou en cas de besoin de réimport) :
`migrate:*` (cinema, events, listings, news, redirects, reference-data, seo, sliders, contacts, click-stats), `migrate:partner-sites`, `images:{movies,news,listings,amenities,partner-sites,events,sliders}` (import différé des médias — voir §13 "Import des images"), `stats:reset` (voir §21 — à exécuter UNE FOIS au moment de la bascule en production, jamais en cron).

**4. Pré-requis serveur pour que le cron fonctionne réellement** (voir §14) : PHP CLI accessible dans le `PATH` (ou chemin absolu dans la ligne cron), `APP_ENV=production`/`APP_DEBUG=false`, connexion DB de production déjà configurée dans le `.env` du serveur (les commandes de scraping/purge écrivent en base), et — pour que §17 (purge Cloudflare) et §20 (indexation Google) fonctionnent réellement en production — `CLOUDFLARE_CACHE_PURGE_ENABLED=true`/`CLOUDFLARE_ZONE_ID`/`CLOUDFLARE_API_TOKEN` ET `GOOGLE_INDEXING_ENABLED=true`/`GOOGLE_INDEXING_CREDENTIALS_JSON_BASE64` renseignés dans ce même `.env` serveur (jamais dans le `.env` de dev/staging).

## 20. Demande d'indexation automatique Google Search Console (06/09/2026, demande client)

Demande client : *"C'est connecté aussi à google search console. Il faut donc qu'à chaque ajout/modif/suppression, cela fait une demande d'indexation automatique vers GSC."*

**Aucun équivalent legacy** : recherche faite dans `old/backEnd/` (routes, contrôleurs) — aucune trace d'appel à une API Google (Indexing, Search Console ou autre) nulle part dans le code source legacy. Mécanisme entièrement nouveau.

**⚠️ Search Console lui-même n'expose aucune API pour la demande d'indexation** — son bouton "Demander une indexation" dans l'interface web n'a pas d'équivalent dans l'API Search Console officielle (`searchconsole.googleapis.com`, qui ne couvre que sitemaps/analytics/vérification de propriété). Le mécanisme réellement disponible et implémenté ici est **l'API Indexing de Google** (`indexing.googleapis.com`), authentifiée par un compte de service Google Cloud (JWT signé RS256, flux OAuth2 "JWT Bearer", RFC 7523) — **officiellement documentée par Google uniquement pour les pages `JobPosting`/`BroadcastEvent`**. L'utiliser pour d'autres types de contenu (actualités, événements, annuaire, annonces) fonctionne en pratique (l'endpoint accepte n'importe quelle URL et déclenche une tentative d'exploration) mais n'est pas officiellement garanti par Google pour ces types — décision du client, appliquée telle quelle, documentée ici pour que ce ne soit pas une surprise si l'efficacité varie.

**Implémentation** (`App\Services\Seo\GoogleIndexingService`, `App\Contracts\HasGoogleIndexingUrl`, `App\Observers\GoogleIndexingObserver`) — architecture calquée sur la purge Cloudflare (§17) :

- **Modèles couverts** : `News`, `Event`, `Listing`, `Classified` — **délibérément SANS `Movie`/`Cinema`**, contrairement à la purge Cloudflare. Raison technique réelle : l'API Indexing traite **une seule URL par appel HTTP** (contrairement à Cloudflare qui accepte des lots de 30) et impose un quota de **200 requêtes/jour/projet par défaut**. `scrape:cinema` sauvegarde des centaines de `Movie` par exécution quotidienne (voir §17) — les inclure épuiserait à lui seul le quota journalier avant même qu'un contenu éditorial (une vraie actualité/un vrai événement publié dans la journée) n'ait sa chance d'être soumis le même jour.
- Chaque modèle implémente `publicUrl(): string` (son URL canonique, réutilisant les mêmes routes que la purge Cloudflare) et `isPubliclyVisible(): bool` (reproduit exactement la condition de visibilité publique déjà utilisée par le contrôleur/`scopePublished` correspondant — ex. `News` : statut `published` ET `end_date` non dépassée, comme `News::scopePublished()`).
- L'Observer envoie `URL_UPDATED` quand le contenu est ACTUELLEMENT visible publiquement, `URL_DELETED` sinon (brouillon, dépublié, expiré, ou réellement supprimé) — y compris pour un contenu qui n'a jamais été publié (une notification "supprimé" pour une URL que Google n'a de toute façon jamais indexée est sans effet réel, plus simple que de suivre la transition précise publié → dépublié).
- **Même découplage accumulation/envoi que Cloudflare** (`queue()`/`flush()`, singleton, `app()->terminating()`) — un seul lot de demandes en fin de processus, jamais un appel HTTP par modèle sauvegardé.
- **Garde de quota supplémentaire** (propre à cette API, pas nécessaire pour Cloudflare) : un compteur journalier en cache limite les envois réels à `GOOGLE_INDEXING_DAILY_QUOTA` (200 par défaut, configurable) — au-delà, les URLs restantes du même lot sont journalisées comme ignorées plutôt que d'échouer ou de risquer un vrai dépassement de quota côté Google.
- **Jeton OAuth2 mis en cache** (~55 min, un jeton Google dure 1h) — évite de re-signer un JWT et de refaire l'échange à chaque `flush()` alors que plusieurs sauvegardes admin peuvent se succéder dans la même heure.
- Authentification : `GOOGLE_INDEXING_CREDENTIALS_JSON_BASE64` (le fichier JSON de clé de compte de service Google Cloud, encodé en base64 sur une ligne — évite les problèmes de retours à la ligne de la clé privée PEM dans un fichier `.env`). Désactivé par défaut (`GOOGLE_INDEXING_ENABLED=false`), jamais activé en local/dev/test (forcé explicitement dans `phpunit.xml` en plus du défaut).
- Ne lève jamais d'exception : une demande d'indexation manquée n'est jamais une raison de faire échouer une sauvegarde admin.

**Mise en place côté Google (à faire par le client/l'ops, pas dans ce dépôt)** : créer un projet Google Cloud, activer l'API "Web Search Indexing", créer un compte de service et télécharger sa clé JSON, ajouter l'adresse e-mail de ce compte de service comme **propriétaire** de la propriété dans Search Console (obligatoire — l'API refuse les URLs d'une propriété où le compte de service n'est pas propriétaire), encoder le fichier JSON en base64 (`base64 -w0 service-account.json`) et renseigner `GOOGLE_INDEXING_CREDENTIALS_JSON_BASE64`/`GOOGLE_INDEXING_ENABLED=true` dans le `.env` de production.

**Tests** : `tests/Feature/GoogleIndexingTest.php` (7 tests) — désactivé par défaut même avec identifiants configurés, `URL_UPDATED` pour un contenu publié, `URL_DELETED` pour un brouillon et pour une suppression, quota journalier respecté (URLs excédentaires ignorées sans échec), jeton OAuth2 mis en cache entre deux `flush()` successifs, et une preuve de bout en bout via une vraie requête HTTP publique confirmant que `app()->terminating()` déclenche l'envoi automatiquement. Clé RSA de test générée hors-ligne via `openssl genrsa` (CLI) plutôt qu'à la volée via `openssl_pkey_new()` en PHP : cette dernière échoue sur l'environnement de développement Windows de ce projet (fichier `openssl.cnf` introuvable) — `openssl_sign()` (utilisé par le service réel), lui, n'a besoin que d'une clé PEM déjà fournie et n'est pas affecté par cette limitation d'environnement.

## 21. Remise à zéro des statistiques de clics (06/09/2026, demande client)

Demande client : *"active bien les stats dans le dashboard et réinitialise en vide, car le stat doit recommencer depuis cette refonte."*

**Constat** : les 3 widgets du dashboard (`ClicksOverview`/`ClicksByTypeChart`/`TopClickedEntities`, voir §13) fonctionnaient déjà correctement — aucun bug de fonctionnement trouvé, "activer les stats" ne nécessitait donc aucun correctif. Le vrai sujet est la seconde partie de la demande : la base de dev contenait déjà **2 454 647 lignes `click_events`** (migrées depuis `t_stat_counter` via `migrate:click-stats`, voir §10) au moment de cette demande — un dashboard fraîchement mis en ligne aurait donc affiché des mois d'historique legacy comme si c'était l'activité "d'aujourd'hui"/"des 30 derniers jours", exactement ce que le client ne veut pas.

**Fix** : nouvelle commande ponctuelle `php artisan stats:reset` (`--force` pour ne pas demander de confirmation, ex. script de déploiement) — vide `click_events`, avec confirmation interactive par défaut (action irréversible). À exécuter **une seule fois**, au moment de la bascule en production ("cutover") — après que `migrate:click-stats` ait éventuellement tourné (ou à la place, si on décide de ne jamais migrer l'historique). Pas un cron (voir §19).

Tests : `tests/Feature/ResetClickStatsTest.php` (4 tests — confirmation acceptée/refusée, `--force`, no-op sur une table déjà vide).

## 22. Cutover production — remigration complète depuis `toulouseweb_old` (06/09/2026)

Demande client : *"J'ai mis à jour la base de données toulouseweb_old avec les données de la prod. Efface tout dans la base toulouseweb et remets tout à jour avec les données de toulouseweb_old, sauf le login/mdp admin. Réuploade toutes les images de prod (old/backEnd/public/). Vérifie que tout est conforme."* — dernière étape avant bascule en production (hors du dépôt `old/`).

### Audit préparatoire (avant toute exécution)

Un audit complet en lecture seule a été fait AVANT d'exécuter quoi que ce soit, pour ne pas remigrer/réuploader à l'aveugle :
- Vérifié que l'admin actuel (`rado.rakotoarivelo@amws.space`) a encore le mot de passe par défaut du seeder — `migrate:fresh --seed` restaure donc exactement le même login, aucune préservation manuelle nécessaire.
- **Gap réel trouvé** : aucune commande `migrate:classifieds` n'existait — seules les catégories d'annonces (`t_annonce_category`) étaient migrées, jamais le contenu (`t_annonce`). Volume réel : 3 lignes (2020), dont une manifestement frauduleuse ("offre de prêt entre particuliers"). Comblé par une nouvelle commande `migrate:classifieds` + `images:classifieds` — les 3 lignes sont importées en statut `pending` (jamais publiées automatiquement, même pour du contenu migré — cohérent avec le brief §8), donc l'admin doit valider les 2 légitimes et rejeter le spam plutôt que de le voir apparaître en ligne de fait. `classifieds` n'avait pas de colonne `legacy_id` du tout (décision initiale documentée : "quasi inutilisées en legacy, non migrées telles quelles") — ajoutée par une migration dédiée pour permettre l'upsert idempotent comme partout ailleurs.
- **Bug réel trouvé et corrigé** : `ImportEventImages` n'indexait QUE `old/backEnd/public/agenda/` (sans "s") — une note antérieure de ce document qualifiait à tort `old/backEnd/public/agendas/` (792 fichiers, avec un "s") de "doublon probable, non prioritaire". Vérification faite : ce n'était pas un doublon, c'est un second dossier legacy distinct, source d'environ 82 % des images d'événements manquantes (867/897 échecs du dernier run avant correctif portaient le préfixe `"agendas/"`). Corrigé (le second dossier est maintenant indexé aussi) — **vérifié en conditions réelles après correctif : le taux d'échec est passé de 897 à seulement 30 (÷29)**.
- **Gap réel trouvé** : les icônes de catégories d'événements (`event_categories.icon`, ex. `agenda/soiree.png`) étaient déjà migrées en base par `migrate:reference-data` mais **jamais résolues/copiées vers le stockage** — cassées partout où affichées (front, admin) depuis le début. Comblé par une nouvelle commande `images:event-categories` (réutilise le même dossier `agenda(s)/` déjà indexé) — **vérifié : 18/21 icônes désormais résolues** (0 avant ce correctif).
- Portfolio "réalisations" de l'agence (`t_realisation_site`, 7 lignes, `old/backEnd/public/realisations/`) : aucun équivalent dans la refonte (page vitrine de l'agence elle-même, pas du contenu client) — **décision produit à prendre avec le client**, non traité ici faute de direction.
- Confirmé structurellement non récupérables depuis ce dépôt de référence (nécessitent un accès réel au stockage de production, pas juste au dump SQL) : une partie substantielle des images d'actualités (~40 %), de films (~90 % résolus, le reste en URLs AlloCiné directes déjà valides) et de fiches annuaire (~98 % résolus) — chiffres exacts ci-dessous.

### Séquence exécutée (dans cet ordre, sur la base de dev locale)

1. `php artisan migrate:fresh --seed` — DESTRUCTIF (toutes les tables supprimées et recréées), **action bloquée par le classificateur de permission automatique de l'environnement** jusqu'à autorisation explicite de l'utilisateur (voir aussi `stats:reset` déjà bloqué de la même façon en §21) — jamais contourné, l'utilisateur a confirmé vouloir que l'agent l'exécute directement.
2. `migrate:reference-data` → `migrate:listings` → `migrate:events` → `migrate:cinema` → `migrate:news` → `migrate:sliders` → `migrate:contacts` → `migrate:partner-sites` → `migrate:classifieds` (nouvelle) → `migrate:seo` → `migrate:redirects` — dans l'ordre documenté en §10, chacune vérifiée individuellement (aucune erreur silencieuse, tous les rejets journalisés sont des cas légitimes — vérifié par échantillonnage des logs, ex. `storage/logs/migration/seo.log` : les 14 390 rejets SEO sont des lignes legacy sans titre ni description).
3. Re-seed des sources de scraping (`ScraperSourcesSeeder`, `AgendaScraperSourcesSeeder`) — nécessaire après `migrate:fresh`, dépend de `migrate:cinema` déjà exécutée (résolution par `cinema_id`).
4. `images:amenities`, `images:partner-sites`, `images:sliders`, `images:classifieds` (nouvelle), `images:movies`, `images:news`, `images:listings`, `images:events`, `images:event-categories` (nouvelle) — dans cet ordre, chacune vérifiée.
5. `migrate:click-stats` **délibérément PAS exécutée** — voir §21/§10 point 11.
6. `php artisan stats:reset --force` — confirmé no-op (`click_events` déjà vide après `migrate:fresh`), exécuté quand même pour verrouiller explicitement l'intention.

### Résultat (comparé aux volumes legacy réels)

| Domaine | Lignes legacy réelles | Lignes migrées | Écart |
|---|---|---|---|
| Catégories (annuaire+agenda+annonces+news) | 1447+25+7+39 | identique | 0 |
| Areas (lieux) | 3845 | 3845 | 0 |
| Amenities | 19 | 19 | 0 |
| Listings (annuaire) | 2984 | 2978 | 6 lignes sans titre exploitable, journalisées |
| Events (agenda) | 19073 | 18773 | 300 lignes journalisées (dates/titres invalides) |
| Cinémas | — | 28 | — |
| Films | 17336 | 17336 | 0 |
| Séances / horaires | 41543 / — | 41543 / 311386 | 0 |
| News | 6197 | 6197 (+2 commentaires sur 14) | 0 |
| Sliders | — | 135 | — |
| Messages de contact | — | 55 | — |
| Sites partenaires | — | 8 | — |
| **Annonces (classifieds)** | **3** | **3** | **0 — comblé ce jour, voir ci-dessus** |
| Métadonnées SEO | — | 1294 (14390 lignes vides ignorées) | — |
| Redirections 301 | — | 6710 | — |
| Statistiques de clics | 2809058 (`t_stat_counter`) | **0 — délibéré** | voir §21 |
| Utilisateurs admin | 1 (préservé) | 1 (même email/mot de passe) | 0 |

**Résolution des images, vérifiée en conditions réelles après les correctifs ci-dessus** :

| Entité | Résolues / Total | Note |
|---|---|---|
| Amenities | 19/19 (100 %) | |
| Sites partenaires | 8/8 (100 %) | |
| Sliders | 134/135 (99,3 %) | |
| **Annonces** | **3/3 (100 %)** | comblé ce jour |
| Films (posters locaux) | 2553/2676 (95,4 %) | reste en URL AlloCiné directe le cas échéant |
| Fiches annuaire (logo+galerie) | 1789/1796 (99,6 %) | |
| Événements | 1752/1782 (98,3 %) | **avant correctif du bug `agendas/` : 881/1778 (49,5 %)** |
| **Catégories d'événements (icônes)** | **18/21 (85,7 %)** | **comblé ce jour — 0 avant** |
| Actualités | 2809/5564 (50,5 %) globalement, mais **seulement 23/233 (9,9 %) parmi les actualités PUBLIÉES** (voir §23) | limite structurelle du dump de référence pour la majorité, un vrai bug de répertoire corrigé pour le reste — voir §23 |

### Vérification finale

- Suite de tests complète rejouée après toutes les migrations : **214 passed (625 assertions)**, aucune régression.
- Pages publiques vérifiées en HTTP réel (200 partout) : `/`, `/actualites`, `/annuaire`, `/agenda`, `/cinema`, `/annonces`.
- Connexion admin vérifiée après remigration : même email, même mot de passe, rôle `super_admin` intact.
- 37 sources de scraping re-seedées (24-25 cinéma + 12 agenda).

### Ce qui reste ouvert, à trancher avec le client avant la mise en prod réelle

1. Portfolio "réalisations" de l'agence — inclure ou abandonner explicitement (voir ci-dessus).
2. Les images restantes non résolues (actualités en particulier, ~60 %) nécessitent un accès réel au stockage de production (`old/backEnd/public/` du serveur live, potentiellement plus complet que le dump de référence utilisé pour cette copie) — à rejouer une fois cet accès disponible, les commandes `images:*` sont idempotentes (un fichier déjà résolu n'est jamais retraité).
3. Deux incohérences mineures de nommage de dossier trouvées par l'audit, non bloquantes, non corrigées (hors périmètre "vérifier que tout est conforme") : `ImportEventImages` écrit dans `events/` en pratique un mélange historique `agenda/`+`events/` (fonctionnellement correct, juste incohérent visuellement) ; `PartnerSiteResource`/`MovieResource` n'ont pas de widget d'upload de fichier en admin (les colonnes `logo`/`poster` sont de simples champs texte) — l'import legacy fonctionne, mais un futur ajout manuel par un admin devra coller une URL au lieu d'uploader un fichier.

## 23. Images d'actualités quasi absentes sur le site public (07/09/2026, demande client)

Demande client, suite au rapport §22 : *"aucune image pour les actualités. À corriger."*

**Analyse** : la statistique globale du §22 (40,5 % résolues, toutes actualités confondues) était correcte mais **trompeuse** — elle mélangeait ~6000 actualités de 2007-2023 (brouillons/archivées, jamais visibles publiquement) avec les articles réellement publiés. Vérifié précisément : parmi les **233 actualités au statut `published`** (les seules visibles sur `/actualites`), **seulement 2 avaient une image résolue avant ce correctif** — d'où l'impression, exacte pour ce que le client voyait réellement sur le site, qu' "aucune" image ne fonctionnait.

**Cause racine identifiée** : les articles les plus RÉCENTS (2023-2026, ceux qui composent l'essentiel du statut `published`) référencent souvent une image déjà utilisée pour un événement ou une fiche annuaire — le site semble partager un même pool d'uploads entre actualités/agenda/annuaire plutôt que d'avoir un dossier `news/` strictement dédié à toutes les époques. **Même mécanisme que le bug déjà corrigé sur `ImportEventImages`** (répertoire de recherche manquant), mais ici l'amélioration est **partielle** : sur 226 actualités publiées avec une image non résolue, une recherche exhaustive dans TOUT `old/backEnd/public/` (index construit sur les ~35 000 fichiers, comparaison par nom de fichier sans extension) a retrouvé une correspondance pour seulement 21 d'entre elles — 14 dans `agendas/`, 3 dans `agenda/`, 3 dans `article/`, 1 dans `bonsplans/`. **Les 205 autres (90,7 % du reste) sont réellement absentes de ce dépôt `old/` de référence** — vérifié qu'aucune ne pointe vers une URL absolue (`http...`, qui n'aurait pas eu besoin d'import) : ce sont bien des noms de fichiers nus, introuvables.

**Fix appliqué** : `ImportNewsImages` indexe désormais aussi `agenda/`, `agendas/`, `article/` et `bonsplans/` en plus de `news/` (même ordre de priorité que la recherche : `news/` d'abord, les autres en repli, pour ne jamais préférer une correspondance accidentelle dans un autre dossier à un fichier réellement présent sous `news/`). Résultat vérifié après réexécution : **23/233 (9,9 %)** actualités publiées ont désormais une image résolue, contre 2/233 avant.

**Ce qui n'est PAS un bug de code, donc pas corrigible depuis ce dépôt** : les 210 actualités publiées restantes sans image référencent des fichiers (ex. `Laloum & Consuelo.webp`, `received_217755234232129.jpeg`, `T07689 - AFFICHE PIFTEAU - V5def.webp`) qui n'existent nulle part dans la copie `old/backEnd/public/` disponible ici. Cette copie a manifestement été capturée à un moment donné et n'a pas suivi les uploads plus récents faits directement sur le serveur de production — **seul un accès réel au stockage de production (le vrai `old/backEnd/public/` du serveur live, pas cette copie de référence) permettra de récupérer ces fichiers**. Les commandes `images:*` sont idempotentes : rejouer `images:news` une fois cet accès disponible ne retraitera que ce qui manque encore, sans risque.

Aucun test automatisé dédié (ces commandes `migrate:*`/`images:*` dépendent d'une vraie connexion `legacy` MySQL et d'un accès disque réel à `old/`, jamais exécutées dans la suite de tests — cohérent avec l'absence de tests pour les 9 autres commandes `images:*`/`migrate:*` existantes). Suite complète rejouée après ce correctif : 214 passed (625 assertions), aucune régression (le changement ne touche que le contenu de l'index de recherche d'un import ponctuel, pas le code applicatif runtime).
