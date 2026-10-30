<?php

namespace App\Filament\Resources\ApiLogs\Pages;

use App\Filament\Resources\ApiLogs\ApiLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApiLogs extends ListRecords
{
    protected static string $resource = ApiLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Wert nachtragen'),
        ];
    }
}
