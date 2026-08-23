<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Category;
use App\Models\Cinema;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Language;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\Page;
use App\Models\PartnerSite;
use App\Models\Screening;
use App\Models\Slider;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration UNIQUEMENT pour valider visuellement la homepage
 * et l'admin en local avant l'exécution du vrai plan de migration (Phase 5,
 * TECHNICAL_DOCUMENTATION.md §10). Ne jamais exécuter en production — les
 * fiches/événements ci-dessous sont fictifs. À vider avant la mise en prod :
 * `php artisan db:seed --class=DemoContentSeeder`.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        Page::firstOrCreate(['key' => 'home'], [
            'title' => 'Accueil',
            'slug' => 'accueil',
            'content' => null,
        ]);

        // --- Annuaire -------------------------------------------------
        $restaurants = Category::firstOrCreate(['slug' => 'restaurants'], ['name' => 'Restaurants', 'level' => 0, 'order' => 1]);
        $sports = Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports', 'level' => 0, 'order' => 2]);
        $enfants = Category::firstOrCreate(['slug' => 'enfants'], ['name' => 'Enfants', 'level' => 0, 'order' => 3]);
        $mariages = Category::firstOrCreate(['slug' => 'mariages'], ['name' => 'Mariages', 'level' => 0, 'order' => 4]);
        $spectacles = Category::firstOrCreate(['slug' => 'spectacles'], ['name' => 'Spectacles', 'level' => 0, 'order' => 5]);
        $sorties = Category::firstOrCreate(['slug' => 'sorties'], ['name' => 'Sorties & Loisirs', 'level' => 0, 'order' => 6]);
        Category::firstOrCreate(['slug' => 'beaute-bien-etre'], ['name' => 'Beauté & Bien-être', 'level' => 0, 'order' => 7]);
        Category::firstOrCreate(['slug' => 'commerces-services'], ['name' => 'Commerces & Services', 'level' => 0, 'order' => 8]);

        $demoListings = [
            ['title' => 'Le Petit Cassoulet', 'city' => 'Toulouse', 'cuisine_type' => 'Cuisine du Sud-Ouest', 'category' => $restaurants],
            ['title' => 'Osteria della Garonne', 'city' => 'Toulouse', 'cuisine_type' => 'Italien', 'category' => $restaurants],
            ['title' => 'Studio Yoga Capitole', 'city' => 'Toulouse', 'cuisine_type' => null, 'category' => $sports],
            ['title' => 'Ludo Récré', 'city' => 'Blagnac', 'cuisine_type' => null, 'category' => $enfants],
            ['title' => 'Salle des Fêtes Les Minimes', 'city' => 'Toulouse', 'cuisine_type' => null, 'category' => $mariages],
        ];

        foreach ($demoListings as $data) {
            $listing = Listing::firstOrCreate(['title' => $data['title']], [
                'tier' => 'paid',
                'status' => 'published',
                'city' => $data['city'],
                'address' => 'Toulouse',
                'phone' => '05 61 00 00 00',
                'cuisine_type' => $data['cuisine_type'],
                'published_at' => now(),
            ]);
            $listing->categories()->syncWithoutDetaching([$data['category']->id]);
        }

        // --- Agenda ----------------------------------------------------
        $zenith = Area::firstOrCreate(['name' => 'Zénith de Toulouse'], ['city' => 'Toulouse']);
        $capitole = Area::firstOrCreate(['name' => 'Théâtre du Capitole'], ['city' => 'Toulouse']);
        $halleAuxGrains = Area::firstOrCreate(['name' => 'Halle aux Grains'], ['city' => 'Toulouse']);

        $theatre = EventCategory::firstOrCreate(['slug' => 'theatre'], ['name' => 'Théâtre', 'color' => '#a63f23', 'order' => 1]);
        $concerts = EventCategory::firstOrCreate(['slug' => 'concerts'], ['name' => 'Concerts', 'color' => '#33404f', 'order' => 2]);
        $festivals = EventCategory::firstOrCreate(['slug' => 'festivals'], ['name' => 'Festivals', 'color' => '#c48a1c', 'order' => 3]);
        EventCategory::firstOrCreate(['slug' => 'expositions'], ['name' => 'Expositions', 'color' => '#445468', 'order' => 4]);

        $demoEvents = [
            ['title' => 'Le Malade Imaginaire', 'area' => $capitole, 'category' => $theatre, 'days' => 5],
            ['title' => 'Nuit Symphonique', 'area' => $halleAuxGrains, 'category' => $concerts, 'days' => 10],
            ['title' => 'Festival Rio Loco', 'area' => $zenith, 'category' => $festivals, 'days' => 20],
        ];

        foreach ($demoEvents as $data) {
            $event = Event::firstOrCreate(['title' => $data['title']], [
                'area_id' => $data['area']->id,
                'status' => 'published',
                'source' => 'manual',
                'start_date' => now()->addDays($data['days']),
            ]);
            $event->categories()->syncWithoutDetaching([$data['category']->id]);
        }

        // --- Cinéma ------------------------------------------------------
        $vf = Language::firstOrCreate(['name' => 'VF']);
        $gaumont = Cinema::firstOrCreate(['name' => 'Gaumont Wilson'], ['is_active' => true]);
        $pathe = Cinema::firstOrCreate(['name' => 'Pathé Palace'], ['is_active' => true]);

        foreach (['Le Comte de Toulouse', 'Sur les Toits Roses', 'Garonne, le Documentaire'] as $i => $title) {
            $movie = Movie::firstOrCreate(['title' => $title], ['release_date' => now()->subDays($i * 3)]);
            Screening::firstOrCreate([
                'cinema_id' => $i % 2 === 0 ? $gaumont->id : $pathe->id,
                'movie_id' => $movie->id,
                'language_id' => $vf->id,
            ]);
        }

        // --- Actualités ---------------------------------------------------
        $vie = NewsCategory::firstOrCreate(['slug' => 'vie-locale'], ['name' => 'Vie locale']);
        foreach ([
            'La Garonne illuminée pour les fêtes de fin d\'année',
            'Nouveau parcours cyclable entre Toulouse et Blagnac',
            'Le marché de Victor Hugo fête ses 100 ans',
        ] as $i => $title) {
            News::firstOrCreate(['title' => $title], [
                'category_id' => $vie->id,
                'excerpt' => 'Actualité de démonstration pour la validation visuelle de la homepage.',
                'body' => '<p>Contenu de démonstration.</p>',
                'status' => 'published',
                'published_at' => now()->subDays($i),
            ]);
        }

        // --- Annonces -------------------------------------------------
        $vehicules = ClassifiedCategory::firstOrCreate(['slug' => 'voitures'], ['name' => 'Voitures']);
        $immobilier = ClassifiedCategory::firstOrCreate(['slug' => 'immobilier'], ['name' => 'Immobilier']);
        ClassifiedCategory::firstOrCreate(['slug' => 'emploi'], ['name' => 'Emploi']);
        ClassifiedCategory::firstOrCreate(['slug' => 'services'], ['name' => 'Services']);

        Classified::firstOrCreate(['title' => 'Citadine récente, faible kilométrage'], [
            'category_id' => $vehicules->id, 'description' => 'Annonce de démonstration.', 'price' => 9800, 'status' => 'published',
        ]);
        Classified::firstOrCreate(['title' => 'T2 lumineux proche métro'], [
            'category_id' => $immobilier->id, 'description' => 'Annonce de démonstration.', 'price' => 750, 'status' => 'published',
        ]);

        // --- Homepage --------------------------------------------------
        $slider = Slider::firstOrCreate(['title' => 'Bannière de démonstration'], [
            'image' => 'https://images.unsplash.com/photo-1596395463813-a0d0c1c94c93?q=80&w=1600&auto=format&fit=crop',
            'client_name' => 'ToulouseWeb',
            'is_active' => true,
        ]);
        $slider->placements()->firstOrCreate(['page' => 'home']);

        PartnerSite::firstOrCreate(['name' => 'Aeromorning'], ['url' => 'https://www.aeromorning.com', 'order' => 1]);
        PartnerSite::firstOrCreate(['name' => 'Toulouse Enfants'], ['url' => 'https://www.toulouse-enfant.com', 'order' => 2]);
    }
}
