<?php

namespace App\Filament\Resources\NewsletterSubscriberResource\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSave;
use App\Filament\Resources\NewsletterSubscriberResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsletterSubscriber extends CreateRecord
{
    use RedirectsToIndexAfterSave;

    protected static string $resource = NewsletterSubscriberResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source'] = 'admin';

        return $data;
    }
}
