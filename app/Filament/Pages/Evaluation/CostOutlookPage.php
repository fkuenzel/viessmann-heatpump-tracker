<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class CostOutlookPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/kosten-prognose';

    protected static ?string $slug = 'auswertungen/kosten-prognose';

    protected static ?string $title = 'Kosten und Jahresprognose';

    protected static ?string $navigationLabel = 'Kosten und Jahresprognose';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 5;

    protected static bool $withComparison = false;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\CostOutlookTable::class,
        ];
    }
}
