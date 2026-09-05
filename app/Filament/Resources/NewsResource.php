<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsResource\Pages;
use App\Filament\Resources\NewsResource\RelationManagers;
use App\Models\News;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Actualités';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Titre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('category_id')
                    ->label('Catégorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('author_id')
                    ->label('Auteur')
                    ->relationship('author', 'name')
                    ->default(fn () => auth()->id()),
                Forms\Components\TextInput::make('excerpt')
                    ->label('Extrait')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\RichEditor::make('body')
                    ->label('Contenu')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->maxSize(4096)
                    ->directory('news'),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options([
                        'draft' => 'Brouillon',
                        'pending' => 'En attente de validation',
                        'published' => 'Publiée',
                        'archived' => 'Archivée',
                    ])
                    ->required()
                    ->default('draft'),
                Forms\Components\DateTimePicker::make('published_at')->label('Date de publication'),

                Forms\Components\Section::make("Informations de l'événement")
                    ->description("Si l'article décrit un événement (salon, brocante, animation...) — laissez vide sinon. L'article ne s'affiche plus sur le site public une fois la date de fin dépassée, quel que soit son statut.")
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Date de début de l\'événement'),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('Date de fin de l\'événement')
                            ->afterOrEqual('start_date')
                            ->helperText("Une fois cette date dépassée, l'article disparaît automatiquement du site public."),
                        Forms\Components\TextInput::make('schedule')
                            ->label('Horaire')
                            ->maxLength(500)
                            ->placeholder('Ex : Tous les jours de 10h à 18h, sauf le lundi')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('address')
                            ->label('Adresse')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('price')
                            ->label('Tarif')
                            ->maxLength(255)
                            ->placeholder('Ex : Gratuit, 5€ - 12€...'),
                        Forms\Components\TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(30),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('website')
                            ->label('Site web')
                            ->url()
                            // Pas de ->maxLength() (demande client, 04/09/2026) : certains
                            // liens dépassent largement 255 caractères (paramètres UTM/tracking),
                            // voir migration widen_news_url_columns (colonne passée en `text`).
                            ->helperText('Lien vers le site officiel, ouvert dans un nouvel onglet sur le site public.'),
                        Forms\Components\TextInput::make('youtube_url')
                            ->label('Lien YouTube')
                            ->url()
                            ->helperText('Vidéo affichée dans la fiche détaillée de l\'article.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(''),
                Tables\Columns\TextColumn::make('title')->label('Titre')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category.name')->label('Catégorie')->badge()->sortable(),
                Tables\Columns\TextColumn::make('author.name')->label('Auteur')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Début événement')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('Fin événement')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Publié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options([
                    'draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publiée', 'archived' => 'Archivée',
                ]),
                Tables\Filters\SelectFilter::make('category')->relationship('category', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')
                    ->label('Publier')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (News $record) => $record->status === 'pending')
                    ->action(fn (News $record) => $record->update(['status' => 'published', 'published_at' => now()])),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit' => Pages\EditNews::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
