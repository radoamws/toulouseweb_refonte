<?php

namespace Tests\Feature;

use App\Filament\Resources\AreaResource\Pages\ListAreas;
use App\Filament\Resources\ClassifiedResource\Pages\ListClassifieds;
use App\Filament\Resources\NewsResource\Pages\ListNews;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Area;
use App\Models\Classified;
use App\Models\ClassifiedCategory;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tri croissant/décroissant sur les colonnes de tableau admin + date de
 * création visible par défaut (demande client, "tous les champs des
 * tableaux... doivent être triables, ajoute par défaut la date de
 * création"). `AreaResource` sert de cas simple représentatif (colonnes
 * texte) ; `ClassifiedResource`/`NewsResource`/`RoleResource` couvrent les
 * cas plus délicats (colonne sur une relation belongsTo, agrégat `counts()`)
 * qui pourraient échouer à l'exécution même si la page s'affiche (voir
 * AdminPanelSmokeTest, qui ne déclenche pas réellement un tri).
 */
class AdminTableSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticatedAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_area_table_sorts_by_name_and_created_at(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        Area::create(['name' => 'Zénith', 'slug' => 'zenith']);
        Area::create(['name' => 'Altigone', 'slug' => 'altigone']);

        Livewire::test(ListAreas::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords([Area::where('name', 'Altigone')->first(), Area::where('name', 'Zénith')->first()], inOrder: true)
            ->sortTable('name', 'desc')
            ->assertCanSeeTableRecords([Area::where('name', 'Zénith')->first(), Area::where('name', 'Altigone')->first()], inOrder: true)
            ->sortTable('created_at');
    }

    /** Colonne sur une relation belongsTo (category.name) + agrégat implicite du badge statut. */
    public function test_classified_table_sorts_by_relation_column(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = ClassifiedCategory::create(['name' => 'Voitures', 'slug' => 'voitures']);
        Classified::create([
            'category_id' => $category->id, 'title' => 'Annonce', 'slug' => 'annonce',
            'description' => 'x', 'contact_email' => 'a@example.test', 'status' => 'published',
        ]);

        Livewire::test(ListClassifieds::class)
            ->sortTable('category.name')
            ->assertSuccessful()
            ->sortTable('status')
            ->assertSuccessful();
    }

    public function test_news_table_sorts_by_relation_columns(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        $category = NewsCategory::create(['name' => 'Vie locale', 'slug' => 'vie-locale']);
        News::create([
            'category_id' => $category->id, 'title' => 'Actu', 'slug' => 'actu',
            'body' => 'x', 'status' => 'published', 'published_at' => now(),
        ]);

        Livewire::test(ListNews::class)
            ->sortTable('category.name')
            ->assertSuccessful()
            ->sortTable('author.name')
            ->assertSuccessful();
    }

    /** Agrégat `counts('users')` — pas une vraie colonne, cas le plus susceptible de casser le tri. */
    public function test_role_table_sorts_by_users_count(): void
    {
        $this->actingAs($this->authenticatedAdmin());
        Role::findOrCreate('editor');

        Livewire::test(ListRoles::class)
            ->sortTable('users_count')
            ->assertSuccessful()
            ->sortTable('users_count', 'desc')
            ->assertSuccessful();
    }
}
