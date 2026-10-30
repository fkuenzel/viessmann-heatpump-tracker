<?php

namespace App\Filament\Resources\Annotations\Pages;

use App\Filament\Resources\Annotations\AnnotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnotations extends ListRecords
{
    protected static string $resource = AnnotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
