<x-layouts.app :seo="['title' => 'Désinscription newsletter', 'robots' => 'noindex,nofollow']">
    <div class="mx-auto max-w-xl px-4 py-16 text-center sm:px-6 lg:px-8">
        <h1 class="font-heading text-2xl font-bold text-ink-900">Vous êtes désinscrit</h1>
        <p class="mt-3 text-ink-600">
            Vous ne recevrez plus la newsletter ToulouseWeb. Vous pouvez vous réinscrire à tout moment depuis la page d'accueil.
        </p>
        <a href="{{ route('home') }}" class="mt-6 inline-flex items-center justify-center rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            Retour à l'accueil
        </a>
    </div>
</x-layouts.app>
