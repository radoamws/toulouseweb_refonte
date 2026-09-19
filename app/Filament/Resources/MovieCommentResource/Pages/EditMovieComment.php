<?php

namespace App\Filament\Resources\MovieCommentResource\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSave;
use App\Filament\Resources\MovieCommentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMovieComment extends EditRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = MovieCommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
