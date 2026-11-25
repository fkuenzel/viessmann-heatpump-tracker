<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class PeriodComparisonPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/periodenvergleich';

    protected static ?string $slug = 'auswertungen/periodenvergleich';

    protected static ?string $title = 'Periodenvergleich';

    protected static ?string $navigationLabel = 'Periodenvergleich';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 1;

    protected static bool $withComparison = true;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\PeriodComparisonTable::class,
        ];
    }
}
