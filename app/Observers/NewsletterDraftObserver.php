<?php

namespace App\Observers;

use App\Models\Classified;
use App\Models\Listing;
use App\Models\News;
use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Model;

/**
 * Génère un BROUILLON de newsletter à chaque publication d'une fiche
 * annuaire/actualité/annonce (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36).
 *
 * ⚠️ Ne déclenche JAMAIS d'envoi — crée uniquement une ligne
 * `newsletters` en `status = draft`, laissée à la validation d'un
 * administrateur dans Filament (voir NewsletterResource). Deux raisons :
 * 1) demande explicite du client le 12/09/2026 : "n'envoie à personne
 *    d'autre que moi avant mon GO" — s'applique à TOUTES les entités,
 *    donc aussi à ces déclenchements automatiques ;
 * 2) un envoi réellement automatique et immédiat, à CHAQUE publication,
 *    enverrait potentiellement plusieurs emails par jour aux mêmes
 *    abonnés (plusieurs fiches annuaire publiées la même journée, etc.) —
 *    un risque de fatigue/désabonnement en masse qui mérite un arbitrage
 *    éditorial du client, pas une décision technique unilatérale.
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
    ];

    protected const LABELS = [
        Listing::class => 'Nouveau sur l\'annuaire ToulouseWeb',
        News::class => 'Actualité ToulouseWeb',
        Classified::class => 'Nouvelle annonce ToulouseWeb',
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

        $title = $model->title;
        $excerpt = match (true) {
            $model instanceof News => $model->excerpt,
            $model instanceof Listing => $model->short_description,
            $model instanceof Classified => $model->description,
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
