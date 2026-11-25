<?php

namespace App\Filament\Resources\MeterReadings\Pages;

use App\Enums\MeterReadingReason;
use App\Filament\Resources\MeterReadings\Concerns\NotifiesAboutPlausibility;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Models\MeterReading;
use App\Models\Setting;
use App\Services\MeterReadingPlausibilityService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ListMeterReadings extends ListRecords
{
    use NotifiesAboutPlausibility;

    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('readNow')
                ->label('Jetzt ablesen')
                ->icon('heroicon-o-bolt')
                ->modalHeading('Zählerstand jetzt erfassen')
                ->modalDescription('Der Ablesezeitpunkt ist der aktuelle Zeitpunkt.')
                ->modalSubmitActionLabel('Speichern')
                ->schema([
                    TextInput::make('counter_ht')
                        ->label('Zählerstand HT (1.8.1)')
                        ->numeric()
                        ->step(0.001)
                        ->suffix('kWh')
                        ->required(),
                    TextInput::make('counter_nt')
                        ->label('Zählerstand NT (1.8.2)')
                        ->numeric()
                        ->step(0.001)
                        ->suffix('kWh')
                        ->required(),
                    Select::make('reason')
                        ->label('Grund der Ablesung')
                        ->options(MeterReadingReason::options())
                        ->default(MeterReadingReason::Regular->value)
                        ->required()
                        ->native(false),
                    Textarea::make('note')
                        ->label('Notiz')
                        ->rows(2)
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $reason = MeterReadingReason::from($data['reason']);
                    $now = Carbon::now()->startOfMinute();

                    $errors = app(MeterReadingPlausibilityService::class)->errors(
                        $now,
                        (float) $data['counter_ht'],
                        (float) $data['counter_nt'],
                        $reason,
                    );

                    if ($errors !== []) {
                        throw ValidationException::withMessages(['counter_ht' => implode(' ', $errors)]);
                    }

                    $reading = MeterReading::create([
                        'timestamp' => $now,
                        'meter_number' => Setting::get('meter_number'),
                        'counter_ht' => $data['counter_ht'],
                        'counter_nt' => $data['counter_nt'],
                        'reason' => $reason,
                        'source' => 'manual',
                        'note' => $data['note'] ?? null,
                    ]);

                    Notification::make()->title('Zählerstand gespeichert')->success()->send();

                    $this->notifyAboutPlausibility($reading);
                }),
            CreateAction::make(),
        ];
    }
}
