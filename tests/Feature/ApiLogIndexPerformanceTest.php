<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Services\ApiLogAggregationService;
use App\Services\PeriodReportService;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schutz vor Performance-Regressionen: Ein Bericht darf nicht je Tag und
 * Spalte eine eigene api_log-Abfrage ausloesen (2.0.0 brauchte dafuer >1.000).
 */
class ApiLogIndexPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedDays(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $day = Carbon::parse('2026-11-24')->subDays($i - 1);

            ApiLog::create([
                'timestamp' => $day->copy()->addDay()->setTime(6, 0),
                'source' => 'scheduled',
                'day_index' => 1,
                'measured_at' => $day->copy()->startOfDay(),
                'power_heating_kwh' => 9,
                'power_dhw_kwh' => 1,
                'power_total_kwh' => 10,
                'thermal_heating_kwh' => 36,
                'thermal_dhw_kwh' => 4,
                'outdoor_temp' => 5,
            ]);
        }
    }

    private function apiLogQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_starts_with($q['query'], 'select "') && str_contains($q['query'], 'from "api_log"'))
            ->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_report_with_comparison_reads_api_log_only_once(): void
    {
        $this->seedDays(70);
        app(ApiLogAggregationService::class)->flush();

        $period = ReportPeriod::forPreset('last_30', null, null, 'previous');

        $queries = $this->apiLogQueries(fn () => app(PeriodReportService::class)->reportWithComparison($period));

        $this->assertLessThanOrEqual(2, $queries, "api_log wurde {$queries}-mal abgefragt");
    }

    public function test_index_is_shared_between_consumers_within_a_request(): void
    {
        $this->seedDays(10);
        app(ApiLogAggregationService::class)->flush();

        $this->assertSame(
            app(ApiLogAggregationService::class),
            app(ApiLogAggregationService::class)
        );

        $queries = $this->apiLogQueries(function () {
            $period = ReportPeriod::forPreset('last_7');
            app(PeriodReportService::class)->report($period);
            app(PeriodReportService::class)->report($period);
            app(ApiLogAggregationService::class)->dailyPowerValue(Carbon::parse('2026-11-20'), 'power_total_kwh');
        });

        $this->assertLessThanOrEqual(1, $queries);
    }

    public function test_index_is_invalidated_on_create_update_and_delete(): void
    {
        $this->seedDays(3);
        $service = app(ApiLogAggregationService::class);
        $date = Carbon::parse('2026-11-24');

        $this->assertEquals(10.0, $service->dailyPowerValue($date, 'power_total_kwh'));

        $row = ApiLog::query()->whereDate('measured_at', '2026-11-24')->firstOrFail();
        $row->update(['power_total_kwh' => 20, 'power_heating_kwh' => 19]);
        $this->assertEquals(20.0, $service->dailyPowerValue($date, 'power_total_kwh'));

        $row->delete();
        $this->assertNull($service->dailyPowerValue($date, 'power_total_kwh'));
    }

    public function test_identical_timestamps_resolve_deterministically_to_latest_row(): void
    {
        $timestamp = Carbon::parse('2026-11-25 06:00:00');

        foreach ([8, 12] as $value) {
            ApiLog::create([
                'timestamp' => $timestamp,
                'source' => 'scheduled',
                'day_index' => 1,
                'measured_at' => Carbon::parse('2026-11-24'),
                'power_heating_kwh' => $value,
                'power_dhw_kwh' => 0,
                'power_total_kwh' => $value,
            ]);
        }

        $service = app(ApiLogAggregationService::class);
        $service->flush();

        $this->assertEquals(12.0, $service->dailyPowerValue(Carbon::parse('2026-11-24'), 'power_total_kwh'));
    }
}
