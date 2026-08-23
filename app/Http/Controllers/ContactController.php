<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Page;
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

        ContactMessage::create(collect($validated)->except('website')->all());

        return redirect()
            ->route('contact.show')
            ->with('status', 'Votre message a bien été envoyé, nous vous répondrons dans les meilleurs délais.');
    }
}
