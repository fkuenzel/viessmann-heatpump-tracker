<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;

class CompressorCyclingPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/kompressor-takten';

    protected static ?string $slug = 'auswertungen/kompressor-takten';

    protected static ?string $title = 'Kompressor und Takten';

    protected static ?string $navigationLabel = 'Kompressor und Takten';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 6;

    protected static bool $withComparison = false;

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\CompressorChart::class,
            \App\Filament\Widgets\Evaluation\CompressorTable::class,
        ];
    }
}
