<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `content:merge-duplicate-area` — demande client, 19/09/2026 : "Il y a 2
 * Areas 'Casino barrière' dans l'admin: supprime l'un et bascule les agendas
 * correspondants a celui supprimé vers celui qui reste."
 */
class MergeDuplicateAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_reassigned_and_the_duplicate_area_is_deleted(): void
    {
        $keep = Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere']);
        $remove = Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere-2']);

        $event = Event::create([
            'title' => 'Spectacle X', 'slug' => 'spectacle-x-merge-test',
            'area_id' => $remove->id, 'status' => 'published', 'start_date' => now()->addWeek(),
        ]);

        Artisan::call('content:merge-duplicate-area', ['keep' => 'casino-theatre-barriere', 'remove' => 'casino-theatre-barriere-2']);

        $this->assertSame($keep->id, $event->fresh()->area_id);
        $this->assertNull(Area::find($remove->id));
        $this->assertNotNull(Area::find($keep->id));
    }

    public function test_soft_deleted_events_are_also_reassigned(): void
    {
        $keep = Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere']);
        $remove = Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere-2']);

        $event = Event::create([
            'title' => 'Ancien doublon', 'slug' => 'ancien-doublon-merge-test',
            'area_id' => $remove->id, 'status' => 'published', 'start_date' => now()->addWeek(),
        ]);
        $event->delete();

        Artisan::call('content:merge-duplicate-area', ['keep' => 'casino-theatre-barriere', 'remove' => 'casino-theatre-barriere-2']);

        $this->assertSame($keep->id, $event->fresh()->area_id);
    }

    public function test_fails_gracefully_when_a_slug_does_not_exist(): void
    {
        Area::create(['name' => 'Casino Théâtre Barrière', 'slug' => 'casino-theatre-barriere']);

        $exitCode = Artisan::call('content:merge-duplicate-area', ['keep' => 'casino-theatre-barriere', 'remove' => 'does-not-exist']);

        $this->assertSame(1, $exitCode);
    }
}
