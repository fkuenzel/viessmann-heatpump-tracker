<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetDailySummary;
use App\Models\WeatherLog;
use Illuminate\Support\Carbon;

class GetDailySummaryTest extends McpTestCase
{
    public function test_summarises_a_completed_day_with_meter_based_estimate(): void
    {
        $this->seedMeterInterval();

        $this->apiLogFor('2026-11-03', [
            'outdoor_temp' => 8.5,
            'compressor_starts' => 112,
            'compressor_hours' => 56.5,
        ], time: '23:50');
        $this->apiLogFor('2026-11-02', ['compressor_starts' => 100, 'compressor_hours' => 50.0], time: '23:50');

        WeatherLog::create([
            'timestamp' => Carbon::parse('2026-11-03 00:00:00', 'Europe/Berlin'),
            'temperature' => 7.2,
            'humidity' => 81,
            'source' => 'archive_daily',
        ]);

        $data = $this->callTool(GetDailySummary::class, ['date' => '2026-11-03']);

        $this->assertSame('2026-11-03', $data['date']);
        $this->assertTrue($data['is_complete']);
        $this->assertSame('app', $data['basis']);

        $this->assertEquals(15.0, $data['power_kwh']['heating']);
        $this->assertEquals(5.0, $data['power_kwh']['dhw']);
        $this->assertEquals(20.0, $data['power_kwh']['total']);
        $this->assertEquals(75.0, $data['thermal_kwh']['total']);
        $this->assertEquals(3.75, $data['cop']);

        $this->assertEquals(8.5, $data['outdoor_temperature_celsius']['plant']);
        $this->assertEquals(7.2, $data['outdoor_temperature_celsius']['external_mean']);
        $this->assertSame(12, $data['compressor']['starts_that_day']);

        // Korrekturfaktor 88 / 80 = 1,1
        $this->assertEquals(1.1, $data['meter_based_estimate']['correction_factor']);
        $this->assertEquals(22.0, $data['meter_based_estimate']['power_total_kwh']);
        $this->assertEquals(16.5, $data['meter_based_estimate']['power_heating_kwh']);
        $this->assertEquals(3.41, $data['meter_based_estimate']['cop']);
    }

    public function test_day_outside_a_meter_interval_has_no_estimate(): void
    {
        $this->seedMeterInterval();
        $this->apiLogFor('2026-11-05', ['power_total_kwh' => 18, 'thermal_heating_kwh' => 54]);

        $data = $this->callTool(GetDailySummary::class, ['date' => '2026-11-06']);

        $this->assertNull($data['meter_based_estimate']['correction_factor']);
        $this->assertNull($data['meter_based_estimate']['power_total_kwh']);
        $this->assertFalse($data['is_complete']);
    }

    public function test_defaults_to_today(): void
    {
        $data = $this->callTool(GetDailySummary::class);

        $this->assertSame('2026-11-06', $data['date']);
        $this->assertFalse($data['is_complete']);
    }

    public function test_rejects_malformed_and_future_dates(): void
    {
        $this->rawToolResponse(GetDailySummary::class, ['date' => '06.11.2026'])->assertHasErrors();
        $this->rawToolResponse(GetDailySummary::class, ['date' => '2026-11-07'])->assertHasErrors();
    }
}
