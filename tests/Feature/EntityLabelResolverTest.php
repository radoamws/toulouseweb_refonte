<?php

namespace Tests\Feature;

use App\Models\Cinema;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Screening;
use App\Models\ScreeningTime;
use App\Services\Stats\EntityLabelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\Stats\EntityLabelResolver — alimente le widget "Top clics"
 * du dashboard admin. Une omission ici (ex. `news` avant ce correctif)
 * fait retomber silencieusement sur le libellé générique "Type #id" au
 * lieu du vrai titre, voir TECHNICAL_DOCUMENTATION.md §13.
 */
class EntityLabelResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_known_entity_types_to_their_real_label(): void
    {
        $resolver = new EntityLabelResolver;

        $listing = Listing::create(['title' => 'Le Bistrot', 'slug' => 'le-bistrot', 'tier' => 'free', 'status' => 'published']);
        $news = News::create(['title' => 'Une actu importante', 'slug' => 'une-actu-importante', 'body' => 'x', 'status' => 'published']);

        $this->assertSame('Le Bistrot', $resolver->resolve('listing', $listing->id));
        $this->assertSame('Une actu importante', $resolver->resolve('news', $news->id));
    }

    public function test_falls_back_to_generic_label_for_unknown_or_deleted_entity(): void
    {
        $resolver = new EntityLabelResolver;

        $this->assertSame('Listing #999', $resolver->resolve('listing', 999));
        $this->assertSame('Unknown_type #1', $resolver->resolve('unknown_type', 1));
    }

    /**
     * ⚠️ Bug réel trouvé et corrigé (15/09/2026, demande client : "j'ai
     * navigué sur les salles/films de cinéma, ça n'apparaît pas dans les
     * stats") : `cinema` (salle) manquait de cette table de correspondance
     * — les clics étaient bien enregistrés dans `click_events` (aucune
     * validation du type côté ClickTrackingController) mais retombaient sur
     * le libellé générique "Cinema #id" au lieu du vrai nom de la salle.
     */
    public function test_resolves_cinema_to_its_real_name(): void
    {
        $resolver = new EntityLabelResolver;
        $cinema = Cinema::create(['name' => 'CGR Blagnac', 'slug' => 'cgr-blagnac', 'is_active' => true]);

        $this->assertSame('CGR Blagnac', $resolver->resolve('cinema', $cinema->id));
        $this->assertSame('Salles de cinéma', $resolver->typeLabel('cinema'));
    }

    /** screening_time n'a pas de colonne "titre" propre — résolu via ses relations (film + salle). */
    public function test_resolves_screening_time_via_its_movie_and_cinema(): void
    {
        $resolver = new EntityLabelResolver;
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug' => 'gaumont-wilson', 'is_active' => true]);
        $movie = Movie::create(['title' => 'Le Comte de Toulouse', 'slug' => 'le-comte-de-toulouse']);
        $screening = Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addWeek(),
        ]);
        $time = ScreeningTime::create(['screening_id' => $screening->id, 'weekday' => 1, 'time' => '20:30:00']);

        $this->assertSame('Le Comte de Toulouse — Gaumont Wilson', $resolver->resolve('screening_time', $time->id));
        $this->assertSame('Horaire #999', $resolver->resolve('screening_time', 999));
        $this->assertSame('Horaires de séance', $resolver->typeLabel('screening_time'));
    }
}
