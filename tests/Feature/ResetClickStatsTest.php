<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan stats:reset` — demande client : les statistiques du
 * dashboard doivent repartir de zéro depuis cette refonte, pas continuer
 * l'historique legacy migré (`migrate:click-stats`). Voir docblock de
 * App\Console\Commands\ResetClickStats.
 */
class ResetClickStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_truncates_click_events_when_confirmed(): void
    {
        ClickEvent::create(['entity_type' => 'news', 'entity_id' => 1, 'created_at' => now()]);
        ClickEvent::create(['entity_type' => 'listing', 'entity_id' => 2, 'created_at' => now()]);

        $this->artisan('stats:reset')
            ->expectsConfirmation('Supprimer définitivement les 2 lignes de click_events (historique de clics) ? Cette action est irréversible.', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, ClickEvent::count());
    }

    public function test_does_nothing_when_confirmation_declined(): void
    {
        ClickEvent::create(['entity_type' => 'news', 'entity_id' => 1, 'created_at' => now()]);

        $this->artisan('stats:reset')
            ->expectsConfirmation('Supprimer définitivement les 1 lignes de click_events (historique de clics) ? Cette action est irréversible.', 'no')
            ->assertSuccessful();

        $this->assertSame(1, ClickEvent::count());
    }

    public function test_force_option_skips_confirmation(): void
    {
        ClickEvent::create(['entity_type' => 'news', 'entity_id' => 1, 'created_at' => now()]);

        $this->artisan('stats:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, ClickEvent::count());
    }

    public function test_is_a_noop_on_an_already_empty_table(): void
    {
        $this->artisan('stats:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, ClickEvent::count());
    }
}
