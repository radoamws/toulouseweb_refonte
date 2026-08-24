<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MissedRedirectResource\Pages;
use App\Models\MissedRedirect;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * 404 fréquentes sans redirection associée (brief §15) — alimenté
 * automatiquement par Controller::redirectOrAbort()/RedirectFallbackController,
 * jamais saisi à la main. Lecture seule + suppression (pour écarter un
 * chemin non pertinent, ex. bruit de scanner) : décider d'ajouter une
 * vraie redirection reste un geste manuel dans RedirectResource, avec le
 * même chemin recopié depuis ici. Voir aussi `php artisan redirects:audit`
 * (même donnée, pour un contrôle en ligne de commande/cron).
 */
class MissedRedirectResource extends Resource
{
    protected static ?string $model = MissedRedirect::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'SEO & Technique';

    protected static ?string $navigationLabel = '404 fréquentes';

    protected static ?string $modelLabel = '404 fréquente';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('hits_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('path')
                    ->label('Chemin demandé')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('hits_count')
                    ->label('Occurrences')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_seen_at')
                    ->label('Vue la 1ère fois')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Vue la dernière fois')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('Écarter'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Écarter la sélection'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMissedRedirects::route('/'),
        ];
    }
}
