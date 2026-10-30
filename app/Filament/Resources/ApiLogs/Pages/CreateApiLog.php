<?php

namespace App\Filament\Resources\ApiLogs\Pages;

use App\Filament\Resources\ApiLogs\ApiLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApiLog extends CreateRecord
{
    protected static string $resource = ApiLogResource::class;
}
