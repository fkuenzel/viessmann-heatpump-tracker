<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Annotations\AnnotationResource;
use App\Models\Annotation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Letzte Notizen/Ereignisse (Einstellungsänderungen, Wartung, Beobachtungen)
 * als zeitliche Referenz neben den Charts - bewusst als Liste statt als
 * Chart.js-Marker umgesetzt, siehe Fahrplan Abschnitt 4.12/Phase 5.
 */
class AnnotationsWidget extends TableWidget
{
    // Sort-Reihenfolge der Dashboard-Widgets explizit festgelegt statt dem
    // alphabetischen discoverWidgets()-Zufall ueberlassen (Fabian 29.10.2026
    // - die Breakdown-Tabelle landete sonst zwischen den Charts statt danach).
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    /**
     * Widget nur anzeigen, wenn mindestens eine Notiz existiert - leer nimmt
     * sonst unnoetig die oberste, prominenteste Position vor den eigentlichen
     * Mess-Charts ein (Fabian 29.10.2026 - Dashboard-Review).
     */
    public static function canView(): bool
    {
        return Annotation::query()->exists();
    }

    protected function getTableHeading(): ?string
    {
        return 'Notizen';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Annotation::query()->latest('timestamp'))
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Zeitpunkt')
                    ->dateTime('d.m.Y H:i'),
                TextColumn::make('category')
                    ->label('Kategorie')
                    ->formatStateUsing(fn (?string $state) => AnnotationResource::CATEGORIES[$state] ?? $state)
                    ->badge(),
                TextColumn::make('note')
                    ->label('Notiz')
                    ->wrap(),
            ])
            ->defaultPaginationPageOption(5);
    }
}
