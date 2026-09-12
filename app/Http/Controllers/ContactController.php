<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Page;
use App\Support\AdminNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Page de contact (brief §11) — refaite, sécurisée (honeypot + throttle sur la route). */
class ContactController extends Controller
{
    public function show(): View
    {
        $page = Page::where('key', 'contact')->first();

        return view('contact.show', ['seo' => $page?->resolveSeo() ?? []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'website' => ['size:0'], // honeypot anti-spam
        ]);

        $contactMessage = ContactMessage::create(collect($validated)->except('website')->all());

        // Demande client (12/09/2026, voir TECHNICAL_DOCUMENTATION.md §36) :
        // "toute personne s'inscrivant dans la page contact s'inscrit
        // automatiquement aussi à la newsletter" — inscription IMPLICITE,
        // sans case à cocher. `subscribeEmail()` ne réactive jamais de force
        // un email déjà désinscrit (contrairement au formulaire newsletter
        // explicite de la home, voir NewsletterSubscriptionController) :
        // une inscription implicite ne doit pas passer outre un
        // désabonnement volontaire antérieur.
        NewsletterSubscriber::subscribeEmail($validated['email'], $validated['name'], 'contact_form');

        // Notification admin (demande client, voir App\Support\AdminNotifier
        // et TECHNICAL_DOCUMENTATION.md §28) — aucun email n'était envoyé
        // nulle part avant cette demande, le seul moyen de voir un nouveau
        // message était de consulter l'admin manuellement.
        AdminNotifier::send(
            'Nouveau message de contact',
            [
                'Nom' => $validated['name'],
                'Email' => $validated['email'],
                'Téléphone' => $validated['phone'] ?? '',
                'Sujet' => $validated['subject'] ?? '',
                'Message' => $validated['message'],
            ],
            route('filament.admin.resources.contact-messages.edit', $contactMessage),
            'Voir ce message',
        );

        return redirect()
            ->route('contact.show')
            ->with('status', 'Votre message a bien été envoyé, nous vous répondrons dans les meilleurs délais.');
    }
}
