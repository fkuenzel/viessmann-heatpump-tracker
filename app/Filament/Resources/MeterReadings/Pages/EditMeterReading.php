<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Filament\Resources\MeterReadings\Concerns\NotifiesAboutPlausibility;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMeterReading extends EditRecord
{
    use NotifiesAboutPlausibility;

    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->notifyAboutPlausibility($this->record);
    }
}
