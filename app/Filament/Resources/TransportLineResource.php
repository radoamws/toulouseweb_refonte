<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransportLineResource\Pages;
use App\Models\TransportLine;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

/**
 * Lignes de transport Tisséo (brief roadmap "contenu", 14/10/2026) —
 * alimentées automatiquement par `transport:import-tisseo-gtfs`
 * (hebdomadaire, voir son docblock), jamais saisies à la main : lecture
 * seule + un déclenchement manuel de l'import (même logique que le bouton
 * "Scraper" de `CinemaResource`).
 */
class TransportLineResource extends Resource
{
    protected static ?string $model = TransportLine::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Transports (Tisséo)';

    protected static ?string $modelLabel = 'ligne de transport';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ligne')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TransportLine::TYPE_LABELS[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_departure')
                    ->label('Premier départ'),
                Tables\Columns\TextColumn::make('last_departure')
                    ->label('Dernier départ'),
                Tables\Columns\TextColumn::make('frequency_minutes')
                    ->label('Fréquence')
                    ->suffix(' min'),
                Tables\Columns\TextColumn::make('trip_count')
                    ->label('Trajets/jour')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Dernier import')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(TransportLine::TYPE_LABELS),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reimport')
                    ->label('Réimporter depuis Tisséo')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function () {
                        $exitCode = Artisan::call('transport:import-tisseo-gtfs');

                        if ($exitCode === 0) {
                            Notification::make()->title('Import Tisséo terminé')->body(trim(Artisan::output()))->success()->send();
                        } else {
                            Notification::make()->title('Échec de l\'import Tisséo')->body(trim(Artisan::output()))->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransportLines::route('/'),
        ];
    }
}
