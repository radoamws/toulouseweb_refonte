<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsResource\Pages\ListNews;
use App\Mail\SubmitterNotification;
use App\Models\Editor;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Espace client actualités, étape 2 (demande client, 16/11/2026) — voir
 * TECHNICAL_DOCUMENTATION.md et tests\Feature\ClassifiedEditSpaceTest (étape
 * 1, même mécanisme).
 */
class NewsEditSpaceTest extends TestCase
{
    use RefreshDatabase;

    /** Depuis le 17/11/2026, voir ClassifiedEditSpaceTest::test_submitting_a_classified_emails_the_submitter_an_account_creation_link(). */
    public function test_submitting_a_news_emails_the_submitter_an_account_creation_link(): void
    {
        Mail::fake();
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);

        $this->post('/actualites/proposer', [
            'category_id' => $category->id,
            'title' => 'Brocante du quartier',
            'body' => 'Une belle brocante.',
            'submitter_email' => 'proposant@example.test',
            'start_date' => now()->addWeek()->format('Y-m-d'),
            'end_date' => now()->addWeek()->format('Y-m-d'),
            'url_verification' => '',
        ]);

        $news = News::firstOrFail();
        $editor = Editor::where('email', 'proposant@example.test')->firstOrFail();
        $this->assertSame($editor->id, $news->editor_id);

        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('proposant@example.test')
            && str_contains($mail->actionUrl, $editor->invitation_token));
    }

    public function test_unknown_edit_token_returns_404(): void
    {
        $this->get('/actualites/mon-espace/un-token-invalide')->assertNotFound();
    }

    public function test_editing_does_not_change_the_live_published_news_until_admin_approves(): void
    {
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Brocante du quartier', 'slug' => 'brocante-du-quartier',
            'body' => 'Une belle brocante.', 'submitter_email' => 'proposant@example.test', 'status' => 'published',
            'start_date' => now()->addWeek(), 'end_date' => now()->addWeek(),
        ]);

        $response = $this->put('/actualites/mon-espace/'.$news->edit_token, [
            'title' => 'Brocante du quartier (annulée)',
            'body' => 'Une belle brocante.',
            'start_date' => $news->start_date->format('Y-m-d'),
            'end_date' => $news->end_date->format('Y-m-d'),
            'url_verification' => '',
        ]);

        $response->assertRedirect(route('actualites.editSpace', $news->edit_token));

        $this->get('/actualites/brocante-du-quartier')->assertOk()->assertSee('Brocante du quartier')->assertDontSee('annulée');

        $news->refresh();
        $this->assertSame('published', $news->status);
        $this->assertSame('Brocante du quartier (annulée)', $news->pending_changes['title']);
    }

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_apply_the_pending_change_from_the_list_action(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        $news = News::create([
            'category_id' => $category->id, 'title' => 'Brocante du quartier', 'slug' => 'brocante-du-quartier',
            'body' => 'Une belle brocante.', 'submitter_email' => 'proposant@example.test', 'status' => 'published',
            'start_date' => now()->addWeek(), 'end_date' => now()->addWeek(),
            'pending_changes' => ['title' => 'Brocante du quartier (annulée)', 'excerpt' => null, 'body' => 'Une belle brocante.', 'schedule' => null, 'address' => null, 'price' => null, 'website' => null],
        ]);

        Livewire::test(ListNews::class)
            ->filterTable('status', null)
            ->callTableAction('applyPendingChanges', $news);

        $this->assertSame('Brocante du quartier (annulée)', $news->fresh()->title);
        $this->assertNull($news->fresh()->pending_changes);
    }
}
