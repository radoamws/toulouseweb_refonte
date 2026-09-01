<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CinemaResource\Pages;
use App\Filament\Resources\CinemaResource\RelationManagers;
use App\Models\Cinema;
use App\Models\ScraperSource;
use App\Services\Scraping\ScraperRunner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CinemaResource extends Resource
{
    protected static ?string $model = Cinema::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Cinéma';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Généré automatiquement depuis le nom — modifiable.'),
                Forms\Components\TextInput::make('address')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('lat')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('lng')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('external_url')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('legacy_id')
                    ->numeric()
                    ->default(null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lat')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lng')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('external_url')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('legacy_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_scraped_at')
                    ->label('Dernier scraping')
                    ->state(fn (Cinema $record) => static::scraperSourceFor($record)?->last_run_at?->diffForHumans() ?? '—')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                // Lancement manuel du scraper AlloCiné pour CETTE salle
                // (demande client) — même orchestration (ScraperRun,
                // last_run_at/last_status) qu'un lancement cron via
                // `scrape:cinema`, voir ScraperRunner. Le "loader" pendant
                // l'exécution est natif à Filament (état de chargement
                // Livewire sur le bouton), rien à coder en plus.
                Tables\Actions\Action::make('scrape')
                    ->label('Scraper')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Cinema $record) => static::scraperSourceFor($record) !== null)
                    ->action(function (Cinema $record) {
                        $source = static::scraperSourceFor($record);

                        if (! $source) {
                            Notification::make()->title('Aucune source de scraping configurée pour cette salle')->warning()->send();

                            return;
                        }

                        $result = app(ScraperRunner::class)->run($source);

                        if ($result['success']) {
                            $stats = $result['stats'];
                            Notification::make()
                                ->title('Scraping terminé')
                                ->body("Trouvés : {$stats['found']}, créés : {$stats['created']}, mis à jour : {$stats['updated']}, ignorés : {$stats['skipped']}.")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Échec du scraping')
                                ->body($result['error'])
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Source de scraping AlloCiné associée à cette salle — voir
     * `ScraperSourcesSeeder`, `config->cinema_id` (une source par salle,
     * jamais partagée). `null` pour les 3 salles sans identifiant AlloCiné
     * exploitable (UGC Toulouse, Le Mermoz, Espace des Nouveautés), voir
     * son docblock.
     */
    protected static function scraperSourceFor(Cinema $cinema): ?ScraperSource
    {
        return ScraperSource::query()
            ->where('type', 'cinema')
            ->where('config->cinema_id', $cinema->id)
            ->first();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ScreeningsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCinemas::route('/'),
            'create' => Pages\CreateCinema::route('/create'),
            'edit' => Pages\EditCinema::route('/{record}/edit'),
        ];
    }
}
