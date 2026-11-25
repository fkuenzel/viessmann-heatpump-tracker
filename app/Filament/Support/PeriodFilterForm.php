<?php

namespace App\Filament\Support;

use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Gemeinsamer Zeitraum-Filter fuer Dashboard und Auswertungsseiten:
 * Zeitraum, Vergleich daneben, Von/Bis nur bei "Benutzerdefiniert".
 */
final class PeriodFilterForm
{
    /**
     * @param  array<int, \Filament\Schemas\Components\Component>  $extra  zusaetzliche seitenspezifische Felder
     */
    public static function section(bool $withComparison = true, array $extra = []): Section
    {
        $fields = [
            Select::make('preset')
                ->label('Zeitraum')
                ->options(ReportPeriod::PRESETS)
                ->default('last_30')
                ->selectablePlaceholder(false)
                ->native(false)
                ->live()
                ->afterStateUpdated(function (string $state, ?string $old, Set $set): void {
                    // "Benutzerdefiniert" startet mit dem zuvor gewählten Zeitraum,
                    // jede andere Voreinstellung füllt Von/Bis mit ihrem Zeitraum.
                    $source = $state === 'custom'
                        ? (array_key_exists((string) $old, ReportPeriod::PRESETS) && $old !== 'custom' ? $old : 'last_30')
                        : $state;

                    $period = ReportPeriodFilters::resolve(['preset' => $source]);
                    $set('from', $period->from->toDateString());
                    $set('to', $period->to->toDateString());
                }),
        ];

        if ($withComparison) {
            // Der Vergleich gehört zum gewählten Zeitraum und steht direkt daneben.
            $fields[] = Select::make('compare')
                ->label('Vergleich mit')
                ->options(ReportPeriod::COMPARE_MODES)
                ->default('previous')
                ->selectablePlaceholder(false)
                ->native(false)
                ->live();
        }

        $fields[] = DatePicker::make('from')
            ->label('Von')
            ->native(false)
            ->displayFormat('d.m.Y')
            ->maxDate(now())
            ->visible(fn (Get $get): bool => $get('preset') === 'custom')
            ->live();
        $fields[] = DatePicker::make('to')
            ->label('Bis')
            ->native(false)
            ->displayFormat('d.m.Y')
            ->maxDate(now())
            ->visible(fn (Get $get): bool => $get('preset') === 'custom')
            ->live();

        return Section::make()
            ->compact()
            ->columnSpanFull()
            ->extraAttributes(['class' => 'w-full'])
            ->schema([...$fields, ...$extra])
            ->columns(['default' => 1, 'md' => 4]);
    }
}
