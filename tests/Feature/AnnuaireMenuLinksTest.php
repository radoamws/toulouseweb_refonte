<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signalement client (16/11/2026, Louise Dentelle) : le lien "Mariages" du
 * menu annuaire (components/site/header.blade.php, $annuaireNav) pointait
 * vers /annuaire/mariages ("s") alors que la catégorie existe en base sous
 * le slug "mariage" (sans "s") — lien en dur désynchronisé du slug réel,
 * jamais détecté car aucun test ne suivait un clic réel sur ces liens.
 *
 * Un seul lien ("Sorties & Loisirs" -> /annuaire/sorties) ne correspond à
 * aucune catégorie connue et n'est pas couvert ici : son intention réelle
 * (catégorie "Loisirs" ? catégorie à créer ?) reste à confirmer côté client
 * avant correctif, voir TECHNICAL_DOCUMENTATION.md.
 */
class AnnuaireMenuLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_annuaire_menu_links_resolve_to_an_existing_category(): void
    {
        foreach (['Restaurants', 'Enfants', 'Sports', 'Mariage', 'Spectacles'] as $name) {
            Category::create([
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name),
                'level' => 0,
                'is_active' => true,
            ]);
        }

        foreach (['/annuaire/restaurants', '/annuaire/enfants', '/annuaire/sports', '/annuaire/mariage', '/annuaire/spectacles'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
