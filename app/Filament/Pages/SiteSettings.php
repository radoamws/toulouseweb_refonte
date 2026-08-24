<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

/**
 * Réglages globaux du site (brief §13) : alimente le JSON-LD Organization,
 * les meta OG par défaut et le pied de page, jusqu'ici en dur dans
 * `components/layouts/app.blade.php` (voir TECHNICAL_DOCUMENTATION.md §13).
 * Une seule ligne (`SiteSetting::current()`) — page de formulaire simple,
 * pas un Resource (rien à lister).
 */
class SiteSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Paramètres du site';

    protected static ?string $title = 'Paramètres du site';

    protected static string $view = 'filament.pages.site-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(SiteSetting::current()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identité')
                    ->schema([
                        TextInput::make('site_name')
                            ->label('Nom du site')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tagline')
                            ->label('Accroche')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->helperText('Utilisée dans le JSON-LD Organization et les meta par défaut (description, Open Graph, Twitter Card).'),
                        FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->maxSize(2048)
                            ->directory('site'),
                        FileUpload::make('default_og_image')
                            ->label('Image Open Graph par défaut')
                            ->image()
                            ->maxSize(4096)
                            ->directory('site')
                            ->helperText('Utilisée quand une page ne définit pas sa propre image de partage.'),
                    ])
                    ->columns(2),
                Section::make('Coordonnées')
                    ->schema([
                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(255),
                        TextInput::make('address')->label('Adresse')->maxLength(255),
                    ])
                    ->columns(3),
                Section::make('Réseaux sociaux')
                    ->description('Alimentent le "sameAs" du JSON-LD Organization et les liens du pied de page.')
                    ->schema([
                        TextInput::make('facebook_url')->label('Facebook')->url()->maxLength(255),
                        TextInput::make('instagram_url')->label('Instagram')->url()->maxLength(255),
                        TextInput::make('twitter_url')->label('X / Twitter')->url()->maxLength(255),
                        TextInput::make('linkedin_url')->label('LinkedIn')->url()->maxLength(255),
                        TextInput::make('youtube_url')->label('YouTube')->url()->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        SiteSetting::current()->update($this->form->getState());

        Notification::make()
            ->title('Paramètres enregistrés')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Enregistrer')
                ->submit('save'),
        ];
    }
}
