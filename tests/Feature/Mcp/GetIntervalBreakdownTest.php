<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetIntervalBreakdown;
use App\Models\PriceHistory;
use App\Services\MeterIntervalBreakdownService;

class GetIntervalBreakdownTest extends McpTestCase
{
    public function test_returns_empty_result_with_note_when_fewer_than_two_readings_exist(): void
    {
        $this->meterReading('2026-11-01 00:00:00', 1000, 500);

        $data = $this->callTool(GetIntervalBreakdown::class);

        $this->assertSame([], $data['intervals']);
        $this->assertSame(0, $data['total_matching']);
        $this->assertNotNull($data['note']);
    }

    public function test_calculates_interval_values_without_tariff(): void
    {
        $this->seedMeterInterval();

        $data = $this->callTool(GetIntervalBreakdown::class);
        $interval = $data['intervals'][0];

        $this->assertSame(1, $data['returned']);
        $this->assertEquals(4.0, $interval['duration_days']);
        $this->assertEquals(22.0, $interval['avg_meter_kwh_per_day']);

        $this->assertEquals(88.0, $interval['consumption_kwh']['meter_total']);
        $this->assertEquals(80.0, $interval['consumption_kwh']['meter_ht']);
        $this->assertEquals(8.0, $interval['consumption_kwh']['meter_nt']);
        $this->assertEquals(80.0, $interval['consumption_kwh']['app_total']);
        $this->assertEquals(1.1, $interval['consumption_kwh']['correction_factor']);
        // (App - Zähler) / Zähler = (80 - 88) / 88
        $this->assertEquals(-9.09, $interval['consumption_kwh']['app_deviation_percent']);

        $this->assertEquals(75.0, $interval['split']['heating_percent']);
        $this->assertEquals(25.0, $interval['split']['dhw_percent']);
        $this->assertEquals(66.0, $interval['split']['real_heating_kwh']);
        $this->assertEquals(22.0, $interval['split']['real_dhw_kwh']);

        $this->assertEquals(75.0, $interval['thermal_kwh']);
        $this->assertEquals(0.85, $interval['cop']['real']);
        $this->assertEquals(0.94, $interval['cop']['app']);
        $this->assertTrue($interval['cop']['reliable']);
        $this->assertNull($interval['cop']['note']);

        $this->assertNull($interval['cost_eur']);
    }

    public function test_includes_costs_split_by_heating_share_when_a_tariff_exists(): void
    {
        $this->seedMeterInterval();

        PriceHistory::create([
            'valid_from' => '2026-10-01',
            'provider_name' => 'Testwerke',
            'tariff_name' => 'Wärmepumpe',
            'base_fee' => 12,
            'base_fee_period' => 'monthly',
            'ht_price' => 0.30,
            'nt_price' => 0.20,
        ]);

        $interval = $this->callTool(GetIntervalBreakdown::class)['intervals'][0];

        // 80 kWh * 0,30 + 8 kWh * 0,20 = 25,60; Grundgebühr 12 / 30 * 4 Tage = 1,60
        $this->assertEquals(27.2, $interval['cost_eur']['total']);
        $this->assertEquals(20.4, $interval['cost_eur']['heating']);
        $this->assertEquals(6.8, $interval['cost_eur']['dhw']);
        $this->assertFalse($interval['cost_eur']['tariff_changed_during_interval']);
    }

    public function test_marks_intervals_shorter_than_one_day_as_unreliable(): void
    {
        $this->seedMeterInterval();
        $this->meterReading('2026-11-05 06:00:00', 1090, 509);

        $data = $this->callTool(GetIntervalBreakdown::class);
        $latest = end($data['intervals']);

        $this->assertSame(2, $data['returned']);
        $this->assertEquals(0.25, $latest['duration_days']);
        $this->assertFalse($latest['cop']['reliable']);
        $this->assertStringContainsString(
            (string) MeterIntervalBreakdownService::MIN_RELIABLE_INTERVAL_DAYS,
            $latest['cop']['note'],
        );
    }

    public function test_limit_returns_the_most_recent_intervals(): void
    {
        $this->seedMeterInterval();
        $this->meterReading('2026-11-05 06:00:00', 1090, 509);
        $this->meterReading('2026-11-06 06:00:00', 1105, 512);

        $data = $this->callTool(GetIntervalBreakdown::class, ['limit' => 1]);

        $this->assertSame(1, $data['returned']);
        $this->assertSame(3, $data['total_matching']);
        $this->assertStringStartsWith('2026-11-06T06:00:00', $data['intervals'][0]['period_to']);
    }

    public function test_date_filter_restricts_intervals_by_reading_date(): void
    {
        $this->seedMeterInterval();
        $this->meterReading('2026-11-06 06:00:00', 1105, 512);

        $data = $this->callTool(GetIntervalBreakdown::class, ['from' => '2026-11-06', 'to' => '2026-11-06']);

        $this->assertSame(1, $data['returned']);
        $this->assertStringStartsWith('2026-11-06T06:00:00', $data['intervals'][0]['period_to']);
    }

    public function test_rejects_inverted_range_and_oversized_limit(): void
    {
        $this->rawToolResponse(GetIntervalBreakdown::class, ['from' => '2026-11-05', 'to' => '2026-11-01'])->assertHasErrors();
        $this->rawToolResponse(GetIntervalBreakdown::class, ['limit' => 500])->assertHasErrors();
    }
}
