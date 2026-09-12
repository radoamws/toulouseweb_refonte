<?php

namespace App\Console\Commands\Migration;

use App\Models\Newsletter;
use Illuminate\Console\Command;

/**
 * Crée le brouillon de la newsletter "relance du site" (demande client,
 * 12/09/2026, voir TECHNICAL_DOCUMENTATION.md §36) — première campagne du
 * nouveau système. Une commande dédiée (plutôt qu'un seed one-shot en
 * tinker) pour rester reproductible si la table `newsletters` est un jour
 * réinitialisée, même si son contenu est éditorial et ne sera pas rejoué
 * en routine (voir aussi SeedCategoryIcons, même logique).
 *
 * ⚠️ Ne l'envoie à personne — crée seulement le brouillon. L'envoi de test
 * (à `services.newsletter.test_recipients` uniquement) se fait ensuite
 * depuis l'admin (bouton "Envoyer un test" de NewsletterResource) ou via
 * `php artisan tinker` -> `app(NewsletterSender::class)->sendTest(...)`.
 */
class SeedRelaunchNewsletter extends Command
{
    protected $signature = 'newsletter:seed-relaunch';

    protected $description = 'Crée le brouillon de la newsletter annonçant la relance du site (demande client 12/09/2026)';

    public function handle(): int
    {
        if (Newsletter::where('trigger_type', 'manual')->where('subject', 'like', '%nouveau visage%')->exists()) {
            $this->warn('Le brouillon de relance existe déjà — rien à faire.');

            return self::SUCCESS;
        }

        $newsletter = Newsletter::create([
            'subject' => 'ToulouseWeb fait peau neuve — découvrez le nouveau visage du site',
            'preview_text' => "Un site plus rapide, plus clair, pensé pour vous faire (re)découvrir Toulouse et sa région.",
            'body_html' => view('emails.partials.relaunch-announcement')->render(),
            'status' => 'draft',
            'trigger_type' => 'manual',
        ]);

        $this->info("Brouillon créé : newsletter #{$newsletter->id}.");
        $this->line('Pour l\'envoyer en test (à '.implode(', ', config('services.newsletter.test_recipients', [])).') :');
        $this->line("  -> depuis l'admin, section Newsletter > Newsletters > \"Envoyer un test\"");

        return self::SUCCESS;
    }
}
