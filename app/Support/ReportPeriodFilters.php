<?php

namespace App\Support;

/**
 * Hilfsfunktion fuer Widgets: loest die Dashboard-Filter ($this->pageFilters)
 * zu einem ReportPeriod auf, inkl. fruehestem Messdatum fuer "Gesamter Zeitraum".
 */
final class ReportPeriodFilters
{
    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function resolve(?array $filters): ReportPeriod
    {
        $earliest = null;

        if (($filters['preset'] ?? null) === 'all') {
            $earliest = app(\App\Services\ApiLogAggregationService::class)
                ->earliestReferenceDate(['power_total_kwh', 'power_heating_kwh', 'power_dhw_kwh']);
        }

        return ReportPeriod::fromFilters($filters, null, $earliest);
    }
}
