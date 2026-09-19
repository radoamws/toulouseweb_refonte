<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MovieCommentResource\Pages;
use App\Models\MovieComment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Modération des avis film (demande client, 19/09/2026) — même workflow
 * STRICT que ClassifiedResource : un avis soumis depuis le front
 * (CinemaController::storeComment()) démarre toujours en `pending`, jamais
 * publié automatiquement. Pas de `rejection_reason`/`moderated_by` ici
 * (colonnes absentes de `movie_comments`, contrairement à `classifieds`) —
 * juste un statut à trancher.
 */
class MovieCommentResource extends Resource
{
    protected static ?string $model = MovieComment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Cinéma';

    protected static ?string $navigationLabel = 'Avis films';

    protected static ?string $modelLabel = 'avis film';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('movie_id')
                    ->label('Film')
                    ->relationship('movie', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('author_name')
                    ->label('Auteur')
                    ->maxLength(255),
                Forms\Components\TextInput::make('author_email')
                    ->label('Email (privé, jamais affiché)')
                    ->email()
                    ->maxLength(255),
                Forms\Components\Select::make('rating')
                    ->label('Note')
                    ->options([5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'])
                    ->default(null),
                Forms\Components\Textarea::make('body')
                    ->label('Avis')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente de validation',
                        'published' => 'Publié',
                        'rejected' => 'Refusé',
                    ])
                    ->required()
                    ->default('pending'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('movie.title')
                    ->label('Film')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('author_name')
                    ->label('Auteur')
                    ->placeholder('Anonyme')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Note')
                    ->formatStateUsing(fn (?int $state) => $state ? str_repeat('★', $state).str_repeat('☆', 5 - $state) : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Avis')
                    ->limit(80)
                    ->wrap(),
                // Modifiable directement dans la liste (même convention que
                // ClassifiedResource/EventResource/NewsResource/ListingResource).
                Tables\Columns\SelectColumn::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente de validation',
                        'published' => 'Publié',
                        'rejected' => 'Refusé',
                    ])
                    ->sortable()
                    ->afterStateUpdated(fn () => Notification::make()->title('Statut mis à jour')->success()->send()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Déposé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options(['pending' => 'En attente', 'published' => 'Publié', 'rejected' => 'Refusé'])
                    ->default('pending'),
                Tables\Filters\SelectFilter::make('movie')
                    ->label('Film')
                    ->relationship('movie', 'title')
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Valider')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (MovieComment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (MovieComment $record) {
                        $record->update(['status' => 'published']);
                        Notification::make()->title('Avis publié')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (MovieComment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (MovieComment $record) {
                        $record->update(['status' => 'rejected']);
                        Notification::make()->title('Avis refusé')->warning()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMovieComments::route('/'),
            'edit' => Pages\EditMovieComment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }
}
