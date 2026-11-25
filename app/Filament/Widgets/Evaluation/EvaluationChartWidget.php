<?php

namespace App\Filament\Widgets\Evaluation;

use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

abstract class EvaluationChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int | string | array $columnSpan = 'full';

    protected function period(): ReportPeriod
    {
        return ReportPeriodFilters::resolve($this->pageFilters);
    }
}
