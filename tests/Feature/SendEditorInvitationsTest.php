<?php

namespace Tests\Feature;

use App\Mail\SubmitterNotification;
use App\Models\Editor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `editeurs:envoyer-invitations` — comptes éditeur, point 5 de la "Grande
 * modification" (demande client, 17/11/2026). Voir docblock de la commande.
 */
class SendEditorInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_sends_nothing(): void
    {
        Mail::fake();
        Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'token-1']);

        Artisan::call('editeurs:envoyer-invitations');

        $this->assertStringContainsString('Comptes éditeur à inviter : 1', Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_send_emails_each_unregistered_editor_their_own_link(): void
    {
        Mail::fake();
        $editor = Editor::create(['email' => 'nouveau@example.test', 'invitation_token' => 'token-1']);

        Artisan::call('editeurs:envoyer-invitations', ['--send' => true]);

        Mail::assertSent(SubmitterNotification::class, fn (SubmitterNotification $mail) => $mail->hasTo('nouveau@example.test')
            && str_contains($mail->actionUrl, 'token-1'));
    }

    public function test_already_registered_editors_are_never_emailed(): void
    {
        Mail::fake();
        Editor::create(['email' => 'deja@example.test', 'password' => Hash::make('x'), 'invitation_token' => null]);

        Artisan::call('editeurs:envoyer-invitations', ['--send' => true]);

        Mail::assertNothingSent();
    }
}
