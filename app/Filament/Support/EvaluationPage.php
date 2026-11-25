<?php

namespace App\Filament\Support;

use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * Basis der Auswertungsseiten (Navigationsgruppe "Auswertungen"): eine
 * Dashboard-artige Seite mit Zeitraum-Filter, deren Widgets die Filter ueber
 * InteractsWithPageFilters lesen. Eigene Filter-Felder je Seite ueber
 * extraFilterFields().
 */
abstract class EvaluationPage extends BaseDashboard
{
    use HasFiltersForm;

    protected static \UnitEnum | string | null $navigationGroup = 'Auswertungen';

    protected static bool $withComparison = true;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([PeriodFilterForm::section(static::$withComparison, $this->extraFilterFields())]);
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function extraFilterFields(): array
    {
        return [];
    }

    public function getColumns(): int | array
    {
        return ['default' => 1, 'md' => 2];
    }

    protected function period(): ReportPeriod
    {
        return ReportPeriodFilters::resolve($this->filters);
    }
}
