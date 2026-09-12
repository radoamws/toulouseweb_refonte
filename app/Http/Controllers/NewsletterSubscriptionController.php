<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inscription/désinscription newsletter (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36). L'inscription depuis le formulaire de
 * contact ne passe PAS par ici — voir ContactController::store(), qui
 * appelle directement NewsletterSubscriber::subscribeEmail().
 */
class NewsletterSubscriptionController
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            // Honeypot anti-spam (même pattern que les autres formulaires
            // publics du site, voir brief §18).
            'website' => ['size:0'],
        ]);

        $subscriber = NewsletterSubscriber::subscribeEmail($validated['email'], $validated['name'] ?? null, 'homepage');

        // Une personne déjà désinscrite qui remplit à nouveau le formulaire
        // exprime une volonté claire de se réinscrire — contrairement au cas
        // "contact form" (inscription implicite, voir ContactController),
        // ici l'action est explicite et volontaire : on réactive.
        if ($subscriber->status === 'unsubscribed') {
            $subscriber->update(['status' => 'active', 'unsubscribed_at' => null]);
        }

        return back()->with('status', 'Merci ! Votre inscription à la newsletter est confirmée.');
    }

    public function unsubscribe(string $token): View
    {
        $subscriber = NewsletterSubscriber::where('unsubscribe_token', $token)->firstOrFail();

        if ($subscriber->status === 'active') {
            $subscriber->unsubscribe();
        }

        return view('newsletter.unsubscribed');
    }
}
