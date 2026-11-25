<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class TemperatureEfficiencyPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/effizienz-aussentemperatur';

    protected static ?string $slug = 'auswertungen/effizienz-aussentemperatur';

    protected static ?string $title = 'Effizienz nach Außentemperatur';

    protected static ?string $navigationLabel = 'Effizienz nach Außentemperatur';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 3;

    protected static bool $withComparison = false;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\TemperatureScatterChart::class,
            \App\Filament\Widgets\Evaluation\TemperatureClassTable::class,
        ];
    }
}
