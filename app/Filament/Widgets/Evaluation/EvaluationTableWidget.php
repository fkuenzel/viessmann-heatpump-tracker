<?php

namespace App\Filament\Widgets\Evaluation;

use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Tabelle mit vorberechneten Zeilen (Arrays) fuer Auswertungsseiten.
 * Subklassen liefern rows() und columns().
 */
abstract class EvaluationTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract protected function rows(): array;

    /**
     * @return array<string, string> Schluessel => Spaltenueberschrift
     */
    abstract protected function columns(): array;

    abstract protected function heading(): string;

    protected function period(): ReportPeriod
    {
        return ReportPeriodFilters::resolve($this->pageFilters);
    }

    protected function emptyText(): string
    {
        return 'Für diesen Zeitraum liegen keine Daten vor.';
    }

    protected function getTableHeading(): ?string
    {
        return $this->heading();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect($this->rows())->mapWithKeys(fn (array $row, int $i): array => [(string) $i => $row])->all())
            ->columns(collect($this->columns())->map(fn (string $label, string $key) => TextColumn::make($key)->label($label)->wrapHeader())->values()->all())
            ->paginated(false)
            ->emptyStateHeading($this->emptyText());
    }
}
