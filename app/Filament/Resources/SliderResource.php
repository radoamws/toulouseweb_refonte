<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SliderResource\Pages;
use App\Filament\Resources\SliderResource\RelationManagers;
use App\Models\Slider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SliderResource extends Resource
{
    protected static ?string $model = Slider::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Accueil';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Titre (interne)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\FileUpload::make('image')
                    ->label('Image')
                    ->image()
                    ->directory('sliders')
                    ->required(),
                Forms\Components\TextInput::make('link_url')
                    ->label('Lien')
                    ->url()
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('client_name')
                    ->label('Société / commerce associé')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\CheckboxList::make('placements_pages')
                    ->label('Pages d\'affichage')
                    ->options([
                        'home' => 'Accueil',
                        'agenda' => 'Agenda',
                        'cinema' => 'Cinéma',
                        'annuaire' => 'Annuaire',
                        'restaurants' => 'Restaurants',
                        'annonces' => 'Annonces',
                    ])
                    ->columns(3)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\CheckboxList $component, ?\App\Models\Slider $record) {
                        $component->state($record?->placements->pluck('page')->all() ?? []);
                    })
                    ->saveRelationshipsUsing(function (\App\Models\Slider $record, array $state) {
                        $record->placements()->delete();
                        foreach ($state as $page) {
                            $record->placements()->create(['page' => $page]);
                        }
                    }),
                Forms\Components\TextInput::make('order')
                    ->label('Ordre')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('delay_ms')
                    ->label('Délai entre slides (ms)')
                    ->required()
                    ->numeric()
                    ->default(5000),
                Forms\Components\DateTimePicker::make('starts_at')->label('Actif à partir de'),
                Forms\Components\DateTimePicker::make('ends_at')->label('Actif jusqu\'au'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('image'),
                Tables\Columns\TextColumn::make('link_url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('client_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('order')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delay_ms')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('legacy_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListSliders::route('/'),
            'create' => Pages\CreateSlider::route('/create'),
            'edit' => Pages\EditSlider::route('/{record}/edit'),
        ];
    }
}
