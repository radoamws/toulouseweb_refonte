<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ListingResource\Pages;
use App\Models\Listing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * Fiches annuaire (brief §5). Les fiches gratuites n'exigent que
 * titre/adresse/téléphone ; les champs riches (galerie, horaires, réseaux
 * sociaux...) ne s'affichent que pour le tier "paid".
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Annuaire';

    protected static ?string $navigationLabel = 'Fiches';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations générales')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Titre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $state, Set $set) => $set('slug', Str::slug($state))),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('tier')
                            ->label('Type de fiche')
                            ->options(['free' => 'Gratuite', 'paid' => 'Payante'])
                            ->required()
                            ->default('free')
                            ->live(),
                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'draft' => 'Brouillon',
                                'pending' => 'En attente de validation',
                                'published' => 'Publiée',
                                'rejected' => 'Refusée',
                                'archived' => 'Archivée',
                            ])
                            ->required()
                            ->default('draft'),
                        Forms\Components\Select::make('categories')
                            ->label('Catégories')
                            ->relationship('categories', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Coordonnées (minimum requis pour une fiche gratuite)')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('address')->label('Adresse')->maxLength(255),
                        Forms\Components\TextInput::make('phone')->label('Téléphone')->tel()->maxLength(255),
                        Forms\Components\TextInput::make('city')->label('Ville')->maxLength(255),
                        Forms\Components\TextInput::make('postal_code')->label('Code postal')->maxLength(10),
                    ]),

                Forms\Components\Section::make('Contenu enrichi (fiches payantes)')
                    ->columns(2)
                    ->visible(fn (Get $get) => $get('tier') === 'paid')
                    ->schema([
                        Forms\Components\TextInput::make('short_description')->label('Accroche')->maxLength(255)->columnSpanFull(),
                        Forms\Components\Textarea::make('description')->label('Description')->columnSpanFull(),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                        Forms\Components\TextInput::make('website')->label('Site web')->url()->maxLength(255),
                        Forms\Components\TextInput::make('reservation_url')->label('Lien réservation')->url()->maxLength(255),
                        Forms\Components\TextInput::make('click_collect_url')->label('Lien click & collect')->url()->maxLength(255),
                        Forms\Components\TextInput::make('cuisine_type')
                            ->label('Type de cuisine (spécifique restaurants)')
                            ->maxLength(255),
                        Forms\Components\Select::make('amenities')
                            ->label('Équipements / pictos')
                            ->relationship('amenities', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->collection('logo')
                            ->image(),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->collection('gallery')
                            ->image()
                            ->multiple()
                            ->reorderable(),
                    ]),

                Forms\Components\Section::make('SEO')
                    ->collapsed()
                    ->relationship('seoMeta')
                    ->schema([
                        Forms\Components\TextInput::make('title')->label('Titre SEO personnalisé')->maxLength(60)
                            ->helperText('Laisser vide pour une génération automatique (voir App\\Services\\Seo\\SeoResolverService).'),
                        Forms\Components\Textarea::make('description')->label('Meta description personnalisée')->maxLength(160),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->description(fn (Listing $record) => $record->city),
                Tables\Columns\TextColumn::make('tier')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'paid' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => $state === 'paid' ? 'Payante' : 'Gratuite'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'rejected', 'archived' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('categories.name')->label('Catégories')->badge(),
                Tables\Columns\TextColumn::make('phone')->label('Téléphone'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')->options(['free' => 'Gratuite', 'paid' => 'Payante']),
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publiée',
                    'rejected' => 'Refusée', 'archived' => 'Archivée',
                ]),
                Tables\Filters\SelectFilter::make('categories')->relationship('categories', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'create' => Pages\CreateListing::route('/create'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
