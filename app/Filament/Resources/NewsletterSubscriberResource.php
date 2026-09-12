<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterSubscriberResource\Pages;
use App\Models\NewsletterSubscriber;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Abonnés newsletter (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36). Alimentée par la home, le formulaire de
 * contact (inscription implicite) et l'import legacy
 * (`newsletter:import-legacy-contacts`, `t_contacts` de toulouseweb_old,
 * ~1885 lignes) — pas de création manuelle en masse prévue ici, seulement
 * un ajout ponctuel possible et la gestion du statut.
 */
class NewsletterSubscriberResource extends Resource
{
    protected static ?string $model = NewsletterSubscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Abonnés';

    protected static ?string $navigationGroup = 'Newsletter';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('email')
                    ->required()
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->label('Nom')
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options(['active' => 'Actif', 'unsubscribed' => 'Désinscrit'])
                    ->required()
                    ->default('active'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nom')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success',
                    default => 'gray',
                })->formatStateUsing(fn (string $state) => $state === 'active' ? 'Actif' : 'Désinscrit'),
                Tables\Columns\TextColumn::make('source')->label('Origine')->formatStateUsing(fn (?string $state) => match ($state) {
                    'homepage' => 'Accueil',
                    'contact_form' => 'Formulaire contact',
                    'legacy_import' => 'Import historique',
                    'admin' => 'Admin',
                    default => $state ?? '—',
                })->badge()->color('gray'),
                Tables\Columns\TextColumn::make('subscribed_at')->label('Inscrit le')->dateTime('d/m/Y')->sortable(),
            ])
            ->defaultSort('subscribed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options([
                    'active' => 'Actif', 'unsubscribed' => 'Désinscrit',
                ])->default('active'),
                Tables\Filters\SelectFilter::make('source')->label('Origine')->options([
                    'homepage' => 'Accueil', 'contact_form' => 'Formulaire contact',
                    'legacy_import' => 'Import historique', 'admin' => 'Admin',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_status')
                    ->label(fn (NewsletterSubscriber $record) => $record->status === 'active' ? 'Désinscrire' : 'Réactiver')
                    ->icon(fn (NewsletterSubscriber $record) => $record->status === 'active' ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (NewsletterSubscriber $record) => $record->status === 'active' ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (NewsletterSubscriber $record) {
                        $record->status === 'active'
                            ? $record->unsubscribe()
                            : $record->update(['status' => 'active', 'unsubscribed_at' => null]);
                        Notification::make()->title('Statut mis à jour')->success()->send();
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
            'index' => Pages\ListNewsletterSubscribers::route('/'),
            'create' => Pages\CreateNewsletterSubscriber::route('/create'),
            'edit' => Pages\EditNewsletterSubscriber::route('/{record}/edit'),
        ];
    }
}
