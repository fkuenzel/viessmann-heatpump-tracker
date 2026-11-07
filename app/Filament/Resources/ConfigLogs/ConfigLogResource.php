<?php

namespace App\Filament\Resources\ConfigLogs;

use App\Filament\Resources\ConfigLogs\Pages\ListConfigLogs;
use App\Models\ConfigLog;
use App\Services\ConfigChangeService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Schreibgeschuetzter Konfigurationsverlauf (ab 1.2.0): zeigt, wann welche
 * Einstellung der Anlage (Heizkurve, Sollwerte, Zeitprogramme, Betriebsart,
 * Ferienprogramm, §14a ...) geaendert wurde - mit altem und neuem Wert.
 * Die Zeilen schreibt ausschliesslich der Sync (ConfigChangeService), deshalb
 * gibt es weder Anlegen noch Bearbeiten.
 */
class ConfigLogResource extends Resource
{
    protected static ?string $model = ConfigLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Konfigurationsverlauf';

    protected static \UnitEnum|string|null $navigationGroup = 'Daten erfassen';

    protected static ?string $modelLabel = 'Konfigurationsänderung';

    protected static ?string $pluralModelLabel = 'Konfigurationsverlauf';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Zeitpunkt')
                    ->dateTime('d.m.Y H:i')
                    ->tooltip('Zeitpunkt des Syncs, der die Änderung erkannt hat (Genauigkeit: Sync-Intervall).')
                    ->sortable(),
                TextColumn::make('feature')
                    ->label('Einstellung')
                    ->formatStateUsing(fn (string $state): string => ConfigChangeService::label($state))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('previous_value')
                    ->label('Alter Wert')
                    ->state(fn (ConfigLog $record): string => ConfigChangeService::formatValues($record->feature, $record->previous_value))
                    ->wrap(),
                TextColumn::make('value')
                    ->label('Neuer Wert')
                    ->state(fn (ConfigLog $record): string => ConfigChangeService::formatValues($record->feature, $record->value))
                    ->wrap(),
                TextColumn::make('is_baseline')
                    ->label('Art')
                    ->state(fn (ConfigLog $record): string => $record->is_baseline ? 'Ausgangswert' : 'Änderung')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Ausgangswert' ? 'gray' : 'warning'),
            ])
            ->defaultSort('timestamp', 'desc')
            ->filters([
                SelectFilter::make('feature')
                    ->label('Einstellung')
                    ->options(collect(ConfigChangeService::FEATURES)
                        ->map(fn (array $definition): string => $definition['label'])
                        ->all()),
                TernaryFilter::make('is_baseline')
                    ->label('Art')
                    ->placeholder('Alle')
                    ->trueLabel('Nur Ausgangswerte')
                    ->falseLabel('Nur Änderungen')
                    ->default(false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfigLogs::route('/'),
        ];
    }
}
