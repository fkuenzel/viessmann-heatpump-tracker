<?php

namespace App\Filament\Resources\Annotations\Pages;

use App\Filament\Resources\Annotations\AnnotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnotation extends CreateRecord
{
    protected static string $resource = AnnotationResource::class;
}
