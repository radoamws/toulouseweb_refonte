<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Filament\Resources\EventResource\RelationManagers;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Agenda';

    protected static ?string $navigationLabel = 'Événements';

    // Le filtre "Théâtre" du menu principal est une simple EventCategory
    // (slug 'theatre'), pas une ressource séparée — voir brief §6.
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
                Forms\Components\Select::make('area_id')
                    ->label('Lieu')
                    ->relationship('area', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->required(),
                        Forms\Components\TextInput::make('address'),
                        Forms\Components\TextInput::make('city'),
                    ]),
                Forms\Components\Select::make('categories')
                    ->label('Catégories')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('subtitle')
                    ->label('Sous-titre')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->directory('events'),
                Forms\Components\TextInput::make('price')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\DateTimePicker::make('start_date')
                    ->label('Date de début')
                    ->required(),
                Forms\Components\DateTimePicker::make('end_date')
                    ->label('Date de fin'),
                Forms\Components\TextInput::make('booking_url')
                    ->label('Lien réservation')
                    ->url()
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Brouillon',
                        'pending' => 'En attente de validation',
                        'published' => 'Publié',
                        'expired' => 'Expiré',
                        'cancelled' => 'Annulé',
                    ])
                    ->required()
                    ->default('draft'),
                Forms\Components\Select::make('source')
                    ->options([
                        'manual' => 'Saisie manuelle',
                        'scraped' => 'Scraping',
                        'user_submitted' => 'Proposé par un visiteur',
                    ])
                    ->required()
                    ->default('manual'),
                Forms\Components\TextInput::make('external_ref')
                    ->label('Référence externe (dédup scraper)')
                    ->maxLength(255)
                    ->default(null)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(''),
                Tables\Columns\TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->description(fn (Event $record) => $record->subtitle),
                Tables\Columns\TextColumn::make('area.name')
                    ->label('Lieu')
                    ->sortable(),
                Tables\Columns\TextColumn::make('categories.name')
                    ->label('Catégories')
                    ->badge(),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Début')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'expired', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')
                    ->label('Origine')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'draft' => 'Brouillon',
                        'pending' => 'En attente',
                        'published' => 'Publié',
                        'expired' => 'Expiré',
                        'cancelled' => 'Annulé',
                    ]),
                Tables\Filters\SelectFilter::make('categories')
                    ->label('Catégorie')
                    ->relationship('categories', 'name'),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
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
