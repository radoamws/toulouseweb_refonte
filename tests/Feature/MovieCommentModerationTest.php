<?php

namespace Tests\Feature;

use App\Filament\Resources\MovieCommentResource\Pages\ListMovieComments;
use App\Models\Movie;
use App\Models\MovieComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Modération admin des avis film (demande client, 19/09/2026) — voir
 * app/Filament/Resources/MovieCommentResource.php. Même pattern que
 * AdminInlineStatusEditingTest (statut modifiable en ligne) et
 * ClassifiedResource (actions Valider/Refuser).
 */
class MovieCommentModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    protected function pendingComment(): MovieComment
    {
        $movie = Movie::create(['title' => 'Film Modération', 'slug' => 'film-moderation']);

        return $movie->comments()->create(['author_name' => 'Alice', 'body' => 'Avis en attente', 'rating' => 4, 'status' => 'pending']);
    }

    public function test_pending_comments_are_not_publicly_visible_until_approved(): void
    {
        $comment = $this->pendingComment();

        $this->get('/cinema/films/'.$comment->movie->slug)->assertOk()->assertDontSee('Avis en attente');
    }

    public function test_admin_can_approve_a_pending_comment(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $comment = $this->pendingComment();

        Livewire::test(ListMovieComments::class)
            ->callTableAction('approve', $comment);

        $this->assertSame('published', $comment->fresh()->status);
        $this->get('/cinema/films/'.$comment->movie->slug)->assertOk()->assertSee('Avis en attente');
    }

    public function test_admin_can_reject_a_pending_comment(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $comment = $this->pendingComment();

        Livewire::test(ListMovieComments::class)
            ->callTableAction('reject', $comment);

        $this->assertSame('rejected', $comment->fresh()->status);
    }

    public function test_status_can_be_changed_inline_from_the_list(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $comment = $this->pendingComment();

        Livewire::test(ListMovieComments::class)
            ->call('updateTableColumnState', 'status', $comment->getKey(), 'published');

        $this->assertSame('published', $comment->fresh()->status);
    }

    public function test_navigation_badge_shows_the_pending_count(): void
    {
        $this->pendingComment();
        $movie = Movie::create(['title' => 'Autre Film', 'slug' => 'autre-film-moderation']);
        $movie->comments()->create(['author_name' => 'Bob', 'body' => 'x', 'status' => 'published']);

        $this->assertSame('1', \App\Filament\Resources\MovieCommentResource::getNavigationBadge());
    }
}
