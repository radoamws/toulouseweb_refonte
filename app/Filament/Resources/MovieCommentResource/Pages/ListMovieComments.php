<?php

namespace App\Filament\Resources\MovieCommentResource\Pages;

use App\Filament\Resources\MovieCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListMovieComments extends ListRecords
{
    protected static string $resource = MovieCommentResource::class;
}
