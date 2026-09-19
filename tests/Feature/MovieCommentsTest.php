<?php

namespace Tests\Feature;

use App\Mail\AdminNotification;
use App\Models\Cinema;
use App\Models\Movie;
use App\Models\MovieComment;
use App\Models\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Avis sur les films (demande client, 19/09/2026 : "Ajoute l'option de
 * pouvoir mettre des commentaires sur les films (à sécuriser)") — même
 * workflow de modération STRICT que les annonces/événements proposés (voir
 * PublicFormsAndNewsTest pour le pattern de référence) : jamais de
 * publication automatique, `status` toujours forcé à `pending`.
 */
class MovieCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function currentlyScreeningMovie(string $title, string $slug): Movie
    {
        $cinema = Cinema::create(['name' => 'Gaumont Wilson', 'slug'  => 'gaumont-wilson-'.$slug, 'is_active' => true]);
        $movie = Movie::create(['title' => $title, 'slug' => $slug]);
        Screening::create([
            'cinema_id' => $cinema->id, 'movie_id' => $movie->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addWeek(),
        ]);

        return $movie;
    }

    public function test_comment_submission_always_starts_pending_and_is_not_publicly_visible(): void
    {
        Mail::fake();
        $movie = $this->currentlyScreeningMovie('Le Comte de Toulouse', 'le-comte-de-toulouse');

        $response = $this->post(route('cinema.movie.comment', $movie->slug), [
            'author_name' => 'Alice',
            'author_email' => 'alice@example.test',
            'rating' => 5,
            'body' => 'Un chef-d\'œuvre !',
            'website' => '', // honeypot vide = humain
        ]);

        $response->assertRedirect(route('cinema.movie', $movie->slug));

        $comment = MovieComment::where('author_name', 'Alice')->firstOrFail();
        $this->assertSame('pending', $comment->status);

        // Pas visible publiquement tant qu'il n'est pas validé par l'admin.
        $this->get('/cinema/films/'.$movie->slug)->assertOk()->assertDontSee('Un chef-d\'œuvre !');

        Mail::assertSent(AdminNotification::class);
    }

    public function test_comment_submission_cannot_inject_a_status_field(): void
    {
        $movie = $this->currentlyScreeningMovie('Tentative', 'tentative');

        $this->post(route('cinema.movie.comment', $movie->slug), [
            'author_name' => 'Bob',
            'author_email' => 'bob@example.test',
            'rating' => 4,
            'body' => 'x',
            'status' => 'published', // ne doit jamais être pris en compte
            'website' => '',
        ]);

        $comment = MovieComment::where('author_name', 'Bob')->firstOrFail();
        $this->assertSame('pending', $comment->status);
    }

    public function test_comment_submission_rejected_when_honeypot_filled(): void
    {
        $movie = $this->currentlyScreeningMovie('Spam Target', 'spam-target');

        $this->post(route('cinema.movie.comment', $movie->slug), [
            'author_name' => 'Bot',
            'author_email' => 'bot@example.test',
            'rating' => 5,
            'body' => 'spam',
            'website' => 'http://spam.example', // honeypot rempli = bot
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('movie_comments', ['author_name' => 'Bot']);
    }

    public function test_comment_submission_requires_a_rating_between_1_and_5(): void
    {
        $movie = $this->currentlyScreeningMovie('Note Invalide', 'note-invalide');

        $this->post(route('cinema.movie.comment', $movie->slug), [
            'author_name' => 'Carl', 'author_email' => 'carl@example.test',
            'rating' => 6, 'body' => 'x', 'website' => '',
        ])->assertSessionHasErrors('rating');

        $this->assertDatabaseMissing('movie_comments', ['author_name' => 'Carl']);
    }

    public function test_comment_submission_requires_an_email(): void
    {
        $movie = $this->currentlyScreeningMovie('Sans Email', 'sans-email');

        $this->post(route('cinema.movie.comment', $movie->slug), [
            'author_name' => 'Dan', 'rating' => 3, 'body' => 'x', 'website' => '',
        ])->assertSessionHasErrors('author_email');
    }

    public function test_published_comments_are_visible_with_their_rating(): void
    {
        $movie = $this->currentlyScreeningMovie('Film Avec Avis', 'film-avec-avis');
        $movie->comments()->create(['author_name' => 'Eve', 'body' => 'Superbe film.', 'rating' => 5, 'status' => 'published']);
        $movie->comments()->create(['author_name' => 'Bot Refuse', 'body' => 'Spam.', 'rating' => 1, 'status' => 'rejected']);
        $movie->comments()->create(['author_name' => 'En Attente', 'body' => 'En cours.', 'rating' => 3, 'status' => 'pending']);

        $response = $this->get('/cinema/films/'.$movie->slug)->assertOk();
        $response->assertSee('Superbe film.');
        $response->assertDontSee('Spam.');
        $response->assertDontSee('En cours.');
        $response->assertSeeText('Avis (1)');
    }

    public function test_average_rating_is_displayed_on_the_movie_page(): void
    {
        $movie = $this->currentlyScreeningMovie('Film Note', 'film-note');
        $movie->comments()->create(['author_name' => 'A', 'body' => 'x', 'rating' => 5, 'status' => 'published']);
        $movie->comments()->create(['author_name' => 'B', 'body' => 'y', 'rating' => 3, 'status' => 'published']);

        $this->get('/cinema/films/'.$movie->slug)->assertOk()->assertSeeText('4.0');
    }

    public function test_cinema_index_highlights_the_most_commented_movies(): void
    {
        $popular = $this->currentlyScreeningMovie('Film Populaire', 'film-populaire');
        $popular->comments()->create(['author_name' => 'A', 'body' => 'x', 'rating' => 5, 'status' => 'published']);
        $popular->comments()->create(['author_name' => 'B', 'body' => 'y', 'rating' => 4, 'status' => 'published']);

        $this->currentlyScreeningMovie('Film Sans Avis', 'film-sans-avis');

        $response = $this->get('/cinema')->assertOk();
        $response->assertSeeText('Les plus commentés');
        $response->assertSeeInOrder(['Les plus commentés', 'Film Populaire']);
    }

    public function test_cinema_index_does_not_show_most_commented_section_when_no_movie_has_comments(): void
    {
        $this->currentlyScreeningMovie('Film Neutre', 'film-neutre');

        $this->get('/cinema')->assertOk()->assertDontSee('Les plus commentés');
    }

    /** Demande client, 18-19/09/2026 : titre "Panorama" + date de la semaine calculée automatiquement. */
    public function test_cinema_index_shows_the_panorama_header_with_the_current_week_range(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 9, 19));

        try {
            $this->currentlyScreeningMovie('Film Semaine', 'film-semaine');

            $response = $this->get('/cinema')->assertOk();
            $response->assertSeeText('Panorama');
            $response->assertSeeText('16 septembre 2026');
            $response->assertSeeText('22 septembre 2026');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }
}
