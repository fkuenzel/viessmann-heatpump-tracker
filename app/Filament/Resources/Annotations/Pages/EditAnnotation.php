<?php

namespace App\Filament\Resources\Annotations\Pages;

use App\Filament\Resources\Annotations\AnnotationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAnnotation extends EditRecord
{
    protected static string $resource = AnnotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
