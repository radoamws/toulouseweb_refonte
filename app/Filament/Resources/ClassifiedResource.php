<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClassifiedResource\Pages;
use App\Models\Classified;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * Annonces. Workflow imposé par le brief §8 : jamais de publication
 * automatique — pending -> published/rejected uniquement via les actions
 * ci-dessous (jamais en modifiant `status` à la main dans un import ou un
 * script, voir App\Models\Classified).
 */
class ClassifiedResource extends Resource
{
    protected static ?string $model = Classified::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Annonces';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('category_id')
                    ->label('Catégorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('user_id')
                    ->label('Auteur (compte)')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->default(null),
                Forms\Components\TextInput::make('title')
                    ->label('Titre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                Forms\Components\TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('description')->required()->columnSpanFull(),
                SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->image()->multiple(),
                Forms\Components\TextInput::make('price')->label('Prix')->numeric()->prefix('€'),
                Forms\Components\TextInput::make('location')->label('Localisation')->maxLength(255),
                Forms\Components\TextInput::make('contact_phone')->label('Téléphone')->tel()->maxLength(255),
                Forms\Components\TextInput::make('contact_email')->label('Email')->email()->maxLength(255),
                Forms\Components\Toggle::make('is_featured')->label('Mise en avant'),
                Forms\Components\DateTimePicker::make('expires_at')->label('Expire le'),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente de validation',
                        'published' => 'Publiée',
                        'rejected' => 'Refusée',
                        'expired' => 'Expirée',
                        'archived' => 'Archivée',
                    ])
                    ->required()
                    ->default('pending')
                    ->helperText('Créée depuis le frontend, une annonce démarre toujours "en attente" — voir brief §8.'),
                Forms\Components\TextInput::make('rejection_reason')
                    ->label('Motif de refus')
                    ->maxLength(255)
                    ->visible(fn (Forms\Get $get) => $get('status') === 'rejected'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Titre')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('Catégorie')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('Auteur')->placeholder('Invité'),
                Tables\Columns\TextColumn::make('price')->label('Prix')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'rejected', 'archived' => 'danger',
                        'expired' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_featured')->label('Mise en avant')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Déposée le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options([
                    'pending' => 'En attente', 'published' => 'Publiée', 'rejected' => 'Refusée',
                    'expired' => 'Expirée', 'archived' => 'Archivée',
                ])->default('pending'),
                Tables\Filters\SelectFilter::make('category')->relationship('category', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Valider')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Classified $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (Classified $record) {
                        $record->update([
                            'status' => 'published',
                            'moderated_by' => auth()->id(),
                            'moderated_at' => now(),
                            'rejection_reason' => null,
                        ]);
                        Notification::make()->title('Annonce publiée')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Classified $record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('rejection_reason')->label('Motif')->required(),
                    ])
                    ->action(function (Classified $record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'moderated_by' => auth()->id(),
                            'moderated_at' => now(),
                            'rejection_reason' => $data['rejection_reason'],
                        ]);
                        Notification::make()->title('Annonce refusée')->warning()->send();
                    }),
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
            'index' => Pages\ListClassifieds::route('/'),
            'create' => Pages\CreateClassified::route('/create'),
            'edit' => Pages\EditClassified::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }
}
