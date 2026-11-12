<?php

namespace App\Services\Newsletter;

use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\News;
use App\Models\Newsletter;

/**
 * Regroupe, UNE FOIS PAR JOUR, tous les brouillons individuels générés par
 * App\Observers\NewsletterDraftObserver (une fiche annuaire/actualité/
 * annonce/événement déposé publiée dans la journée) en UN SEUL email
 * récapitulatif professionnel (demande client, 12/11/2026 : "faire un
 * envoi automatique... sans mon intervention").
 *
 * Pourquoi regrouper plutôt que renvoyer chaque brouillon individuel tel
 * quel : plusieurs fiches peuvent être validées la même journée, et un
 * email par fiche spammerait les abonnés — risque déjà identifié dans le
 * docblock de l'observer. Un seul digest quotidien, construit et envoyé
 * automatiquement par App\Console\Commands\Newsletter\SendEntityDigest
 * (appelée par `webcron:run`), reste fidèle à la demande ("sans
 * intervention") sans ce risque.
 *
 * Les brouillons sources ne sont JAMAIS envoyés individuellement : une fois
 * fondus dans le digest, ils passent en `status = merged` (jamais
 * supprimés, pour garder la traçabilité dans l'admin Filament) — exclus du
 * prochain passage par la condition `status = draft` ci-dessous, donc
 * aucun risque de les ressasser ni de les renvoyer deux fois.
 */
class EntityDigestSender
{
    protected const CATEGORIES = [
        'listing_published' => 'Annuaire',
        'news_published' => 'Actualités',
        'classified_published' => 'Annonces',
        'event_published' => 'Événements',
    ];

    public function build(): ?Newsletter
    {
        $drafts = Newsletter::where('status', 'draft')
            ->whereIn('trigger_type', array_keys(self::CATEGORIES))
            ->whereNotNull('triggerable_type')
            ->with('triggerable')
            ->oldest()
            ->get();

        if ($drafts->isEmpty()) {
            return null;
        }

        $items = [];

        foreach ($drafts as $draft) {
            $entity = $draft->triggerable;

            // Fiche supprimée ou dépubliée entre-temps : le brouillon est
            // quand même marqué "merged" plus bas (pour ne pas le ressasser
            // indéfiniment chaque jour), mais n'apparaît pas dans le digest.
            if ($entity && $this->isStillPublished($entity)) {
                $items[self::CATEGORIES[$draft->trigger_type]][] = [
                    'title' => $entity->title,
                    'excerpt' => $draft->preview_text,
                    'url' => $entity->publicUrl(),
                ];
            }
        }

        Newsletter::whereIn('id', $drafts->pluck('id'))->update(['status' => 'merged']);

        if (empty($items)) {
            return null;
        }

        $count = array_sum(array_map('count', $items));

        return Newsletter::create([
            'subject' => 'ToulouseWeb — les nouveautés du jour ('.$count.')',
            'preview_text' => 'Annuaire, actualités, annonces, événements : le point sur ce qui vient d\'être publié sur ToulouseWeb.',
            'body_html' => view('emails.partials.entity-digest', ['items' => $items])->render(),
            'status' => 'draft',
            'trigger_type' => 'entity_digest',
        ]);
    }

    protected function isStillPublished(object $entity): bool
    {
        return match (true) {
            $entity instanceof Listing, $entity instanceof News, $entity instanceof Classified, $entity instanceof Event => $entity->status === 'published',
            default => false,
        };
    }
}
