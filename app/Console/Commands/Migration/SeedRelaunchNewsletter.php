<?php

namespace App\Console\Commands\Migration;

use App\Models\Newsletter;
use Illuminate\Console\Command;

/**
 * Crée (ou met à jour) le brouillon de la newsletter "relance du site"
 * (demande client, 12/09/2026, puis contenu complété le 10/11/2026 pour
 * couvrir toutes les rubriques du front — notamment les Transports,
 * ajoutés après la rédaction initiale — voir TECHNICAL_DOCUMENTATION.md
 * §36). Une commande dédiée (plutôt qu'un seed one-shot en tinker) pour
 * rester reproductible si la table `newsletters` est un jour réinitialisée,
 * même si son contenu est éditorial et ne sera pas rejoué en routine (voir
 * aussi SeedCategoryIcons, même logique).
 *
 * ⚠️ Met à jour le contenu d'un brouillon EXISTANT (si rejouée après une
 * modification de la vue `emails.partials.relaunch-announcement`), mais ne
 * touche JAMAIS un envoi déjà parti (`status = sent`) — un contenu déjà
 * envoyé à de vrais abonnés ne doit plus bouger rétroactivement, même si la
 * commande est relancée par erreur.
 *
 * ⚠️ Ne l'envoie à personne — crée/actualise seulement le brouillon. L'envoi
 * de test (à `services.newsletter.test_recipients` uniquement) se fait
 * ensuite depuis l'admin (bouton "Envoyer un test" de NewsletterResource) ou
 * via `php artisan tinker` -> `app(NewsletterSender::class)->sendTest(...)`.
 */
class SeedRelaunchNewsletter extends Command
{
    protected $signature = 'newsletter:seed-relaunch';

    protected $description = 'Crée/met à jour le brouillon de la newsletter annonçant la relance du site (demande client 12/09/2026)';

    public function handle(): int
    {
        $existing = Newsletter::where('trigger_type', 'manual')->where('subject', 'like', '%nouveau visage%')->first();

        if ($existing && $existing->status === 'sent') {
            $this->warn("Le brouillon de relance a déjà été envoyé (newsletter #{$existing->id}) — rien à faire, contenu jamais modifié rétroactivement.");

            return self::SUCCESS;
        }

        $newsletter = Newsletter::updateOrCreate(
            ['trigger_type' => 'manual', 'subject' => 'ToulouseWeb fait peau neuve — découvrez le nouveau visage du site'],
            [
                'preview_text' => "Un site plus rapide, plus clair, pensé pour vous faire (re)découvrir Toulouse et sa région.",
                'body_html' => view('emails.partials.relaunch-announcement')->render(),
                'status' => 'draft',
            ]
        );

        $this->info(($existing ? 'Brouillon mis à jour' : 'Brouillon créé')." : newsletter #{$newsletter->id}.");
        $this->line('Pour l\'envoyer en test (à '.implode(', ', config('services.newsletter.test_recipients', [])).') :');
        $this->line("  -> depuis l'admin, section Newsletter > Newsletters > \"Envoyer un test\"");

        return self::SUCCESS;
    }
}
