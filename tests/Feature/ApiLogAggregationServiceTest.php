<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Services\ApiLogAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApiLogAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiLogAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ApiLogAggregationService;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function row(string $timestamp, int $dayIndex, array $values): ApiLog
    {
        $ts = Carbon::parse($timestamp);

        return ApiLog::create(array_merge([
            'timestamp' => $ts,
            'source' => 'scheduled',
            'day_index' => $dayIndex,
            'measured_at' => $dayIndex === 0 ? $ts : $ts->copy()->startOfDay()->subDays($dayIndex),
        ], $values));
    }

    public function test_total_falls_back_to_heating_plus_dhw_when_api_total_is_smaller(): void
    {
        // Realer Fall 04.11.2026: Gesamt 22,7 < Heizen 21,7 + Warmwasser 2,1
        $this->row('2026-11-05 06:00:00', 1, [
            'power_total_kwh' => 22.7,
            'power_heating_kwh' => 21.7,
            'power_dhw_kwh' => 2.1,
        ]);

        $day = Carbon::parse('2026-11-04');

        $this->assertEqualsWithDelta(23.8, $this->service->dailyPowerValue($day, 'power_total_kwh'), 0.001);
        $this->assertEqualsWithDelta(22.7, $this->service->rawDailyValue($day, 'power_total_kwh'), 0.001);
    }

    public function test_total_keeps_api_value_when_it_is_larger_than_the_parts(): void
    {
        $this->row('2026-11-05 06:00:00', 1, [
            'power_total_kwh' => 30.0,
            'power_heating_kwh' => 21.7,
            'power_dhw_kwh' => 2.1,
        ]);

        $this->assertEqualsWithDelta(30.0, $this->service->dailyPowerValue(Carbon::parse('2026-11-04'), 'power_total_kwh'), 0.001);
    }

    public function test_total_without_parts_is_returned_unchanged(): void
    {
        $this->row('2026-11-05 06:00:00', 1, ['power_total_kwh' => 12.5]);

        $this->assertEqualsWithDelta(12.5, $this->service->dailyPowerValue(Carbon::parse('2026-11-04'), 'power_total_kwh'), 0.001);
    }

    public function test_total_is_derived_from_parts_when_api_total_is_missing(): void
    {
        $this->row('2026-11-05 06:00:00', 1, [
            'power_heating_kwh' => 10.0,
            'power_dhw_kwh' => 2.0,
        ]);

        $this->assertEqualsWithDelta(12.0, $this->service->dailyPowerValue(Carbon::parse('2026-11-04'), 'power_total_kwh'), 0.001);
    }

    public function test_thermal_is_weighted_at_the_interval_edges_like_power(): void
    {
        foreach (['2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
            // Abgeschlossene Tage: Strom 10, Thermik 40 je Tag
            $this->row(Carbon::parse($date)->addDay()->setTime(6, 0)->toDateTimeString(), 1, [
                'power_total_kwh' => 10,
                'power_heating_kwh' => 10,
                'power_dhw_kwh' => 0,
                'thermal_heating_kwh' => 40,
                'thermal_dhw_kwh' => 0,
            ]);
        }

        // 04.11. 12:00 bis 06.11. 12:00 = 2 Tage -> 0,5 + 1 + 0,5
        $from = Carbon::parse('2026-11-04 12:00:00');
        $to = Carbon::parse('2026-11-06 12:00:00');

        $power = $this->service->weightedSum($from, $to, 'power_total_kwh')['value'];
        $thermal = $this->service->weightedThermalSum($from, $to)['value'];

        $this->assertEqualsWithDelta(20.0, $power, 0.001);
        $this->assertEqualsWithDelta(80.0, $thermal, 0.001);
        $this->assertEqualsWithDelta(4.0, $thermal / $power, 0.001);
    }

    public function test_running_day_is_weighted_by_data_coverage(): void
    {
        // Laufender Tag, gepullt um 12:00 (day_index 0): Wert deckt nur 00:00-12:00 ab.
        $this->row('2026-11-10 12:00:00', 0, [
            'power_total_kwh' => 12,
            'power_heating_kwh' => 12,
            'power_dhw_kwh' => 0,
        ]);

        // Intervall 06:00-12:00 = die zweite Haelfte der abgedeckten Zeit -> 6 kWh,
        // nicht 12 * 6/24 = 3 kWh.
        $value = $this->service->weightedSum(
            Carbon::parse('2026-11-10 06:00:00'),
            Carbon::parse('2026-11-10 12:00:00'),
            'power_total_kwh',
        )['value'];

        $this->assertEqualsWithDelta(6.0, $value, 0.001);
    }

    public function test_missing_days_are_reported(): void
    {
        $this->row('2026-11-05 06:00:00', 1, [
            'power_total_kwh' => 10,
            'power_heating_kwh' => 10,
            'power_dhw_kwh' => 0,
        ]);

        $result = $this->service->weightedSum(
            Carbon::parse('2026-11-04 00:00:00'),
            Carbon::parse('2026-11-06 00:00:00'),
            'power_total_kwh',
        );

        $this->assertEqualsWithDelta(10.0, $result['value'], 0.001);
        $this->assertSame(['2026-11-05', '2026-11-06'], $result['missing_dates']);
    }
}
