<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class HotWaterPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/warmwasser';

    protected static ?string $slug = 'auswertungen/warmwasser';

    protected static ?string $title = 'Warmwasser';

    protected static ?string $navigationLabel = 'Warmwasser';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-fire';

    protected static ?int $navigationSort = 4;

    protected static bool $withComparison = false;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\HotWaterTable::class,
            \App\Filament\Widgets\Evaluation\HotWaterChart::class,
        ];
    }
}
