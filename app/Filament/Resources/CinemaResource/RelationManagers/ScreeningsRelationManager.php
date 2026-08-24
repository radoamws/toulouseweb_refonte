<?php

namespace App\Filament\Resources\CinemaResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Séances d'une salle (`screenings`) + leurs horaires précis
 * (`screening_times`, gérés inline via un Repeater). Complète
 * `AllocineDriver` (Phase 8, `scrape:cinema`) qui alimente automatiquement
 * ces mêmes tables pour les 24 salles suivies — utile ici pour les salles
 * non couvertes par le scraper (indépendants, art et essai sans page
 * AlloCiné exploitable) ou pour corriger ponctuellement une séance scrapée
 * (voir TECHNICAL_DOCUMENTATION.md §13).
 *
 * `weekday` (0-6) reprend la convention legacy de `t_cine_proj_heures.jour`
 * (0 = dimanche, comme PHP `date('w')`) — voir App\Models\ScreeningTime.
 */
class ScreeningsRelationManager extends RelationManager
{
    protected static string $relationship = 'screenings';

    protected static ?string $title = 'Séances';

    /** legacy_id t_cine_lang.id => libellé, 0-6 = jour de semaine (dimanche=0), voir ScreeningTime. */
    private const WEEKDAYS = [
        0 => 'Dimanche',
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
    ];

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('movie_id')
                    ->label('Film')
                    ->relationship('movie', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('language_id')
                    ->label('Langue')
                    ->relationship('language', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\DatePicker::make('start_date')
                    ->label('Début de la fenêtre de programmation')
                    ->required(),
                Forms\Components\DatePicker::make('end_date')
                    ->label('Fin de la fenêtre de programmation')
                    ->required()
                    ->afterOrEqual('start_date'),
                Forms\Components\Select::make('types')
                    ->label('Types de projection')
                    ->relationship('types', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Forms\Components\Toggle::make('preview')
                    ->label('Avant-première'),
                Forms\Components\Toggle::make('staff_pick')
                    ->label('Coup de cœur'),
                Forms\Components\Repeater::make('times')
                    ->label('Horaires')
                    ->relationship('times')
                    ->schema([
                        Forms\Components\Select::make('weekday')
                            ->label('Jour')
                            ->options(self::WEEKDAYS)
                            ->required(),
                        Forms\Components\TimePicker::make('time')
                            ->label('Heure')
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TextInput::make('booking_url')
                            ->label('Lien de réservation')
                            ->url()
                            ->maxLength(255)
                            ->default(null),
                    ])
                    ->columns(3)
                    ->addActionLabel('Ajouter un horaire')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('movie.title')
                    ->label('Film')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('language.name')
                    ->label('Langue'),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Début')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('Fin')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('times_count')
                    ->label('Horaires')
                    ->counts('times'),
                Tables\Columns\IconColumn::make('preview')
                    ->label('Avant-première')
                    ->boolean(),
                Tables\Columns\IconColumn::make('staff_pick')
                    ->label('Coup de cœur')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
