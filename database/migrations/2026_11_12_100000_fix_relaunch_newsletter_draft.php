<?php

use App\Models\Newsletter;
use Illuminate\Database\Migrations\Migration;

/**
 * Demande client (12/11/2026) : en production, la newsletter "ToulouseWeb
 * fait peau neuve" était déjà au statut `sent` (recipient_count renseigné —
 * donc un VRAI envoi à tous les abonnés actifs via
 * `NewsletterSender::sendToAll()`, pas un simple test, voir son docblock)
 * mais avec l'ANCIEN gabarit, antérieur aux 2 correctifs du 12/11/2026
 * ("Toutes les séances..." + abonnement auto des dépositions validées).
 * `php artisan newsletter:seed-relaunch` refuse volontairement de retoucher
 * une campagne déjà `sent` (voir son docblock : "contenu jamais modifié
 * rétroactivement") — un simple redéploiement du code ne suffisait donc pas
 * à nettoyer cet enregistrement obsolète dans l'admin.
 *
 * Changement de DONNÉE uniquement, via une migration (plutôt qu'en direct en
 * production) pour passer par la revue de code/CI-CD normale du dépôt —
 * même logique que `reorder_homepage_top_categories.php` : supprime
 * l'ancienne campagne (déjà délivrée, son HTML figé n'a plus d'utilité) et
 * sème une campagne `draft` fraîche avec le contenu à jour, prête à être
 * relue puis envoyée depuis Filament par le client.
 *
 * Ne s'applique QUE si l'enregistrement fautif (`status = sent`) existe
 * réellement — sans effet sur un environnement (local, CI, tests) où le
 * brouillon est déjà à l'état `draft` attendu, pour ne pas perturber les
 * tests qui comptent les lignes de `newsletters` (`NewsletterTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $stale = Newsletter::where('trigger_type', 'manual')
            ->where('subject', 'like', '%nouveau visage%')
            ->where('status', 'sent')
            ->exists();

        if (! $stale) {
            return;
        }

        Newsletter::where('trigger_type', 'manual')
            ->where('subject', 'like', '%nouveau visage%')
            ->delete();

        Newsletter::create([
            'trigger_type' => 'manual',
            'subject' => 'ToulouseWeb fait peau neuve — découvrez le nouveau visage du site',
            'preview_text' => 'Un site plus rapide, plus clair, pensé pour vous faire (re)découvrir Toulouse et sa région.',
            'body_html' => view('emails.partials.relaunch-announcement')->render(),
            'status' => 'draft',
        ]);
    }

    public function down(): void
    {
        Newsletter::where('trigger_type', 'manual')
            ->where('subject', 'like', '%nouveau visage%')
            ->where('status', 'draft')
            ->delete();
    }
};
