<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetCurrentStatus;
use App\Models\WeatherLog;
use Illuminate\Support\Carbon;

class GetCurrentStatusTest extends McpTestCase
{
    public function test_returns_nulls_instead_of_failing_on_an_empty_database(): void
    {
        $data = $this->callTool(GetCurrentStatus::class);

        $this->assertNull($data['last_sync']['at']);
        $this->assertNull($data['last_sync']['age_minutes']);
        $this->assertNull($data['today_so_far']['power_total_kwh']);
        $this->assertNull($data['outdoor_temperature']['plant_celsius']);
        $this->assertNull($data['device']['flow_temp_celsius']);
        $this->assertFalse($data['today_so_far']['is_complete']);
    }

    public function test_reports_todays_values_device_status_and_sync_age(): void
    {
        $this->apiLogFor('2026-11-06', [
            'power_heating_kwh' => 6,
            'power_dhw_kwh' => 2,
            'power_total_kwh' => 8,
            'thermal_heating_kwh' => 24,
            'thermal_dhw_kwh' => 6,
            'outdoor_temp' => 7.5,
            'compressor_starts' => 130,
            'compressor_hours' => 61.25,
            'flow_temp' => 31.5,
            'return_temp' => 27.0,
            'system_pressure' => 1.8,
            'internal_pump_status' => 'on',
            'volumetric_flow' => 960,
            'compressor_active' => true,
            'compressor_phase' => 'ready',
        ], time: '11:30');

        WeatherLog::create([
            'timestamp' => Carbon::parse('2026-11-06 11:00:00', 'Europe/Berlin'),
            'temperature' => 6.4,
            'humidity' => 83,
            'source' => 'live_snapshot',
        ]);

        $data = $this->callTool(GetCurrentStatus::class);

        $this->assertSame(30, $data['last_sync']['age_minutes']);
        $this->assertSame('scheduled', $data['last_sync']['source']);

        $this->assertEquals(8.0, $data['today_so_far']['power_total_kwh']);
        $this->assertEquals(30.0, $data['today_so_far']['thermal_total_kwh']);
        $this->assertEquals(3.75, $data['today_so_far']['cop_so_far']);

        $this->assertEquals(7.5, $data['outdoor_temperature']['plant_celsius']);
        $this->assertEquals(6.4, $data['outdoor_temperature']['external_celsius']);
        $this->assertEquals(83.0, $data['outdoor_temperature']['external_humidity_percent']);

        $this->assertEquals(31.5, $data['device']['flow_temp_celsius']);
        $this->assertEquals(1.8, $data['device']['system_pressure_bar']);
        $this->assertTrue($data['device']['internal_pump_on']);
        $this->assertTrue($data['device']['compressor_active']);
        $this->assertSame('ready', $data['device']['compressor_phase']);

        $this->assertSame(130, $data['compressor_counters']['total_starts']);
        $this->assertEquals(61.25, $data['compressor_counters']['total_hours']);
    }

    public function test_manual_backfill_row_does_not_count_as_last_sync(): void
    {
        $this->apiLogFor('2026-11-05', ['power_total_kwh' => 20], time: '23:45');
        $this->apiLogFor('2026-11-06', ['thermal_heating_kwh' => 10], source: 'backfill', time: '11:55');

        $data = $this->callTool(GetCurrentStatus::class);

        $this->assertSame('scheduled', $data['last_sync']['source']);
        $this->assertSame(Carbon::parse('2026-11-05 23:45:00', 'Europe/Berlin')->toIso8601String(), $data['last_sync']['at']);
    }
}
