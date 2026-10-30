<?php

namespace App\Filament\Resources\ApiLogs\Pages;

use App\Filament\Resources\ApiLogs\ApiLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApiLog extends EditRecord
{
    protected static string $resource = ApiLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
