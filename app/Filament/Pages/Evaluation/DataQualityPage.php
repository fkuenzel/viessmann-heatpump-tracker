<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class DataQualityPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/datenqualitaet';

    protected static ?string $slug = 'auswertungen/datenqualitaet';

    protected static ?string $title = 'Datenqualität';

    protected static ?string $navigationLabel = 'Datenqualität';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 7;

    protected static bool $withComparison = false;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\QualityOverviewTable::class,
            \App\Filament\Widgets\Evaluation\QualityGapsTable::class,
            \App\Filament\Widgets\Evaluation\QualityIntervalsTable::class,
        ];
    }
}
