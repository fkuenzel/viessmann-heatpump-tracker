<?php

namespace App\Filament\Resources\ConfigLogs\Pages;

use App\Filament\Resources\ConfigLogs\ConfigLogResource;
use Filament\Resources\Pages\ListRecords;

class ListConfigLogs extends ListRecords
{
    protected static string $resource = ConfigLogResource::class;
}
