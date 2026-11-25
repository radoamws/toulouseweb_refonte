<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Support\SubmitterNotifier;
use Illuminate\Console\Command;

/**
 * Rappel avant la fin de l'essai gratuit OU de l'abonnement annuel payant
 * d'une fiche annuaire (demande client, 24/11/2026, "la bonne formule
 * d'incitation" ; rectification "100€ par an, pas définitivement",
 * 25/11/2026 — voir Listing::TRIAL_MONTHS/isPaid() et
 * TECHNICAL_DOCUMENTATION.md §83). Les deux cas partagent `premium_until`
 * mais un flag "déjà rappelé" SÉPARÉ (`trial_reminder_sent_at` vs
 * `renewal_reminder_sent_at`) : un abonnement payant qui se renouvelle remet
 * `renewal_reminder_sent_at` à `null` (voir
 * StripePaymentService::markListingAsPaid()) pour que le rappel puisse se
 * redéclencher l'année suivante — contrairement à `trial_reminder_sent_at`,
 * jamais réinitialisé (un seul essai gratuit par fiche).
 *
 * Envoyé 14 jours avant `premium_until`, vers `submitter_email` uniquement
 * (une fiche historique migrée sans email n'a jamais ce champ renseigné,
 * voir Listing::booted() — rien à envoyer, pas d'erreur).
 *
 * Lancée depuis le WebCron quotidien (voir App\Console\Commands\RunWebCron)
 * — pas de `Schedule::` ici, cette app ne tourne jamais en tâche de fond
 * réelle en production (hébergement mutualisé, voir docblock de RunWebCron).
 */
class RemindListingTrialExpiring extends Command
{
    protected $signature = 'listings:remind-trial-expiring';

    protected $description = "Envoie un rappel aux fiches annuaire (essai gratuit ou abonnement payant) dont les options payantes offertes/l'abonnement se terminent dans 14 jours";

    public function handle(): int
    {
        $trialListings = Listing::query()
            ->where('tier', '!=', 'paid')
            ->whereNotNull('premium_until')
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('submitter_email')
            ->whereBetween('premium_until', [now(), now()->addDays(14)])
            ->get();

        foreach ($trialListings as $listing) {
            SubmitterNotifier::send(
                $listing->submitter_email,
                'Votre essai gratuit se termine bientôt',
                [
                    'Fiche' => $listing->title,
                    "Options offertes jusqu'au" => $listing->premium_until->translatedFormat('d F Y'),
                ],
                route('annuaire.editSpace', $listing->edit_token),
                'Gérer ma fiche',
            );

            $listing->update(['trial_reminder_sent_at' => now()]);
        }

        $renewalListings = Listing::query()
            ->where('tier', 'paid')
            ->whereNotNull('premium_until')
            ->whereNull('renewal_reminder_sent_at')
            ->whereNotNull('submitter_email')
            ->whereBetween('premium_until', [now(), now()->addDays(14)])
            ->get();

        foreach ($renewalListings as $listing) {
            SubmitterNotifier::send(
                $listing->submitter_email,
                'Votre abonnement annuel se termine bientôt',
                [
                    'Fiche' => $listing->title,
                    "Abonnement actif jusqu'au" => $listing->premium_until->translatedFormat('d F Y'),
                    'Renouvellement' => '100€ pour 1 an de plus',
                ],
                route('annuaire.editSpace', $listing->edit_token),
                'Renouveler ma fiche',
            );

            $listing->update(['renewal_reminder_sent_at' => now()]);
        }

        $total = $trialListings->count() + $renewalListings->count();
        $this->info("Rappels envoyés : {$total} (essai gratuit : {$trialListings->count()}, renouvellement payant : {$renewalListings->count()})");

        return self::SUCCESS;
    }
}
