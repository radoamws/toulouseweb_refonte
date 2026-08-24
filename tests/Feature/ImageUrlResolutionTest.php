<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Slider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un `<img src="{{ $model->image }}">` brut sur une colonne alimentée par
 * Filament\Forms\FileUpload casse l'affichage : FileUpload stocke un chemin
 * RELATIF au disque `public` (ex: "sliders/xxx.jpg"), pas une URL. Voir
 * App\Models\Concerns\ResolvesImageUrl et TECHNICAL_DOCUMENTATION.md §13.
 */
class ImageUrlResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_relative_disk_path_resolves_to_storage_url(): void
    {
        $slider = Slider::create(['title' => 'Test', 'image' => 'sliders/photo.jpg', 'is_active' => true]);

        $this->assertSame(Storage::disk('public')->url('sliders/photo.jpg'), $slider->image_url);
        $this->assertStringContainsString('/storage/sliders/photo.jpg', $slider->image_url);
    }

    public function test_absolute_url_passes_through_unchanged(): void
    {
        $slider = Slider::create(['title' => 'Test', 'image' => 'https://example.test/photo.jpg', 'is_active' => true]);

        $this->assertSame('https://example.test/photo.jpg', $slider->image_url);
    }

    public function test_null_image_resolves_to_null(): void
    {
        $event = Event::create(['title' => 'Test', 'slug' => 'test', 'status' => 'draft', 'start_date' => now()]);

        $this->assertNull($event->image_url);
    }

    public function test_data_uri_passes_through_unchanged(): void
    {
        // Constaté sur de vraies données legacy (t_cine_film.image) : quelques
        // lignes stockent directement une data URI au lieu d'un nom de fichier.
        $uri = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';
        $slider = Slider::create(['title' => 'Test', 'image' => $uri, 'is_active' => true]);

        $this->assertSame($uri, $slider->image_url);
    }
}
