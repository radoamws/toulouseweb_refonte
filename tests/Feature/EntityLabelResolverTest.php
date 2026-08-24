<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\News;
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
}
