<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterResource\Pages;
use App\Models\Newsletter;
use App\Models\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md
 * §36). Deux actions d'envoi séparées à dessein (voir
 * App\Services\Newsletter\NewsletterSender) :
 *
 * - "Envoyer un test" : toujours disponible, n'atteint JAMAIS un vrai
 *   abonné — uniquement `services.newsletter.test_recipients`.
 * - "Envoyer à tous les abonnés" : bloquée tant que
 *   `NEWSLETTER_SENDING_ENABLED` n'est pas activé dans `.env` — demande
 *   explicite du client le 12/09/2026 ("je donnerai le GO"), voir
 *   config/services.php pour le détail. Affiche le nombre réel d'abonnés
 *   actifs qui seront contactés avant confirmation.
 */
class NewsletterResource extends Resource
{
    protected static ?string $model = Newsletter::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Newsletters';

    protected static ?string $navigationGroup = 'Newsletter';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('subject')
                    ->label('Objet de l\'email')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('preview_text')
                    ->label('Texte d\'aperçu')
                    ->helperText('Court texte affiché par les clients mail à côté de l\'objet (non visible dans le corps de l\'email).')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\RichEditor::make('body_html')
                    ->label('Contenu')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('trigger_info')
                    ->label('Origine')
                    ->visible(fn (?Newsletter $record) => $record && $record->trigger_type)
                    ->content(fn (?Newsletter $record) => match ($record?->trigger_type) {
                        'listing_published' => 'Brouillon généré automatiquement à la publication d\'une fiche annuaire.',
                        'news_published' => 'Brouillon généré automatiquement à la publication d\'une actualité.',
                        'classified_published' => 'Brouillon généré automatiquement à la publication d\'une annonce.',
                        'scraping_digest' => 'Brouillon généré automatiquement après un scraping agenda/cinéma.',
                        default => null,
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')->label('Objet')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->color(fn (string $state) => match ($state) {
                    'sent' => 'success',
                    default => 'gray',
                })->formatStateUsing(fn (string $state) => match ($state) {
                    'sent' => 'Envoyée',
                    default => 'Brouillon',
                }),
                Tables\Columns\TextColumn::make('trigger_type')->label('Origine')->formatStateUsing(fn (?string $state) => match ($state) {
                    'listing_published' => 'Annuaire',
                    'news_published' => 'Actualité',
                    'classified_published' => 'Annonce',
                    'scraping_digest' => 'Scraping agenda/cinéma',
                    default => 'Manuelle',
                })->badge()->color('gray'),
                Tables\Columns\TextColumn::make('test_sent_at')->label('Test envoyé le')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('recipient_count')->label('Destinataires')->placeholder('—'),
                Tables\Columns\TextColumn::make('sent_at')->label('Envoyée le')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Créée le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options([
                    'draft' => 'Brouillon',
                    'sent' => 'Envoyée',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('send_test')
                    ->label('Envoyer un test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'Envoi immédiat à : '.implode(', ', config('services.newsletter.test_recipients', [])).'. Aucun autre destinataire ne recevra cet email.')
                    ->action(function (Newsletter $record) {
                        $count = app(NewsletterSender::class)->sendTest($record);
                        $count > 0
                            ? Notification::make()->title("Email de test envoyé ({$count})")->success()->send()
                            : Notification::make()->title('Aucune adresse de test configurée')->warning()->body('Voir NEWSLETTER_TEST_RECIPIENTS dans .env.')->send();
                    }),
                Tables\Actions\Action::make('send_all')
                    ->label('Envoyer à tous les abonnés')
                    ->icon('heroicon-o-envelope')
                    ->color('danger')
                    ->visible(fn (Newsletter $record) => $record->status !== 'sent')
                    ->requiresConfirmation()
                    ->modalHeading('Envoyer à tous les abonnés actifs ?')
                    ->modalDescription(fn () => NewsletterSubscriber::active()->count().' abonné(s) actif(s) recevront cet email. Cette action est irréversible.')
                    ->action(function (Newsletter $record) {
                        try {
                            $count = app(NewsletterSender::class)->sendToAll($record);
                            Notification::make()->title("Newsletter mise en file pour {$count} abonné(s)")->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Envoi désactivé')->danger()->body($e->getMessage())->send();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewsletters::route('/'),
            'create' => Pages\CreateNewsletter::route('/create'),
            'edit' => Pages\EditNewsletter::route('/{record}/edit'),
        ];
    }
}
