<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\Concerns\NotifiesAboutPlausibility;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMeterReading extends CreateRecord
{
    use NotifiesAboutPlausibility;

    protected static string $resource = MeterReadingResource::class;

    protected function afterCreate(): void
    {
        $this->notifyAboutPlausibility($this->record);
    }
}
