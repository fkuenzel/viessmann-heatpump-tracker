<?php

namespace App\Filament\Resources\MeterReadings\Concerns;

use App\Models\MeterReading;
use App\Services\MeterReadingPlausibilityService;
use Filament\Notifications\Notification;

trait NotifiesAboutPlausibility
{
    protected function notifyAboutPlausibility(MeterReading $reading): void
    {
        $warnings = app(MeterReadingPlausibilityService::class)->warnings($reading);

        if ($warnings === []) {
            return;
        }

        Notification::make()
            ->title('Bitte Ablesung prüfen')
            ->body(implode("\n", $warnings))
            ->warning()
            ->persistent()
            ->send();
    }
}
