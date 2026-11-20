<?php

namespace App\Observers;

use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Model;

/**
 * Génère un BROUILLON de newsletter à chaque publication d'une fiche
 * annuaire/actualité/annonce/événement déposé (demande client, 12/09/2026,
 * étendue aux événements le 12/11/2026, voir TECHNICAL_DOCUMENTATION.md
 * §36).
 *
 * ⚠️ Ne déclenche JAMAIS d'envoi ICI — crée uniquement une ligne
 * `newsletters` en `status = draft`. Un email par fiche publiée
 * spammerait les abonnés si plusieurs fiches sortent la même journée ;
 * à la place, `App\Services\Newsletter\EntityDigestSender` regroupe
 * UNE FOIS PAR JOUR (déclenché par `webcron:run`, voir
 * `App\Console\Commands\Newsletter\SendEntityDigest`) tous les brouillons
 * encore `draft` de cet observer en UN SEUL email récapitulatif, envoyé
 * automatiquement à tous les abonnés (sans intervention manuelle, demande
 * client 12/11/2026) — sous réserve de `NEWSLETTER_SENDING_ENABLED`, qui
 * reste le coupe-circuit général. Les brouillons individuels sont ensuite
 * marqués `status = merged` (jamais envoyés tels quels, jamais supprimés —
 * traçabilité dans l'admin).
 *
 * Événements ET actualités : seuls ceux `source = 'user_submitted'`
 * génèrent un brouillon (filtré dans `maybeCreateDraft()`, pas via la liste
 * de modèles de `AppServiceProvider`) — les événements/actualités scrapés
 * (plusieurs par jour, voir `GOOGLE_INDEXING_MODELS`/
 * `SUBMITTER_SUBSCRIPTION_MODELS` pour la même distinction ailleurs) ne
 * doivent jamais en générer.
 *
 * Se déclenche quand le statut PASSE à "published" — soit par transition
 * (`updated()`, `wasChanged('status')`, ex. pending -> published via
 * l'action "Valider"), soit à la création directe d'une fiche déjà publiée
 * (`created()`, ex. création admin directe en statut "Publiée"). Deux
 * méthodes séparées plutôt qu'un seul `saved()` :
 * 1) `wasChanged('status')` seul ne suffit PAS pour détecter la création :
 *    `performInsert()` n'appelle jamais `syncChanges()` (seul
 *    `performUpdate()` le fait), donc `wasChanged()` est TOUJOURS `false`
 *    juste après une création, quelle que soit la valeur initiale du champ
 *    (même piège déjà documenté sur `wasChanged('slug')` dans
 *    App\Observers\CloudflarePurgeObserver) ;
 * 2) ⚠️ et `wasRecentlyCreated` (l'alternative évidente) NE CONVIENT PAS
 *    non plus : Eloquent ne le remet JAMAIS à `false` après une création —
 *    il reste `true` pour toute la durée de vie de l'instance PHP, y
 *    compris lors d'un `update()` ultérieur sur ce même objet (piège
 *    trouvé en pratique : un test créait puis modifiait le même `$listing`
 *    dans la même requête, générant un second brouillon à tort). D'où le
 *    séparément `created()` (une seule fois, juste après l'insertion) et
 *    `updated()` (`wasChanged('status')`, jamais vrai sur une simple
 *    modification d'une fiche déjà publiée) : entre les deux, aucun risque
 *    de doublon ni d'oubli.
 */
class NewsletterDraftObserver
{
    protected const TRIGGERS = [
        Listing::class => 'listing_published',
        News::class => 'news_published',
        Classified::class => 'classified_published',
        Event::class => 'event_published',
    ];

    protected const LABELS = [
        Listing::class => 'Nouveau sur l\'annuaire ToulouseWeb',
        News::class => 'Actualité ToulouseWeb',
        Classified::class => 'Nouvelle annonce ToulouseWeb',
        Event::class => 'Nouvel événement ToulouseWeb',
    ];

    public function created(Model $model): void
    {
        $this->maybeCreateDraft($model, $model->status === 'published');
    }

    public function updated(Model $model): void
    {
        $this->maybeCreateDraft($model, $model->status === 'published' && $model->wasChanged('status'));
    }

    protected function maybeCreateDraft(Model $model, bool $justPublished): void
    {
        $triggerType = self::TRIGGERS[$model::class] ?? null;
        if (! $triggerType || ! $justPublished) {
            return;
        }

        if ($model instanceof Event && $model->source !== 'user_submitted') {
            return;
        }

        // Actualités scrapées (demande client, 20/11/2026, voir
        // App\Services\Scraping\News\LeJournalToulousainDriver) — plusieurs
        // par jour, jamais "déposées" par quelqu'un, ne doivent jamais
        // générer de brouillon individuel (uniquement
        // `ScrapingDigestBuilder`, voir ScrapeNews::handle()).
        // ⚠️ Volontairement `=== 'scraped'` ici, PAS `!== 'user_submitted'`
        // (contrairement à Event ci-dessus) : une actualité `source =
        // 'manual'` (saisie admin, valeur par défaut de la colonne, tout le
        // contenu créé avant l'ajout de ce champ) doit continuer à générer
        // un brouillon comme avant — seul le scraping est nouveau ici.
        if ($model instanceof News && $model->source === 'scraped') {
            return;
        }

        $title = $model->title;
        $excerpt = match (true) {
            $model instanceof News => $model->excerpt,
            $model instanceof Listing => $model->short_description,
            $model instanceof Classified => $model->description,
            $model instanceof Event => $model->description,
            default => null,
        };
        $excerpt = $excerpt ? \Illuminate\Support\Str::limit(strip_tags((string) $excerpt), 200) : null;

        Newsletter::create([
            'subject' => self::LABELS[$model::class].' : '.$title,
            'preview_text' => $excerpt,
            'body_html' => view('emails.partials.entity-announcement', [
                'heading' => self::LABELS[$model::class],
                'title' => $title,
                'excerpt' => $excerpt,
                'url' => $model->publicUrl(),
            ])->render(),
            'status' => 'draft',
            'trigger_type' => $triggerType,
            'triggerable_type' => $model::class,
            'triggerable_id' => $model->id,
        ]);
    }
}
