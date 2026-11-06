<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\CheckAnomalies;
use App\Models\Setting;

class CheckAnomaliesTest extends McpTestCase
{
    /**
     * Fünf Vergleichstage (30.10.-03.11.) mit je 20 kWh, gestern (05.11.) mit
     * $yesterdayKwh, plus ein frischer Sync heute.
     */
    private function seedConsumption(float $yesterdayKwh): void
    {
        foreach (['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $this->apiLogFor($date, ['power_total_kwh' => 20]);
        }

        $this->apiLogFor('2026-11-05', ['power_total_kwh' => $yesterdayKwh]);
        $this->apiLogFor('2026-11-06', ['power_total_kwh' => 4], time: '11:45');
    }

    public function test_reports_ok_for_a_normal_day_and_fresh_sync(): void
    {
        $this->seedConsumption(21);

        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('ok', $data['status']);
        $this->assertSame([], $data['findings']);
        $this->assertSame('ok', $data['consumption_anomaly']['status']);
        $this->assertSame('ok', $data['sync_outage']['status']);
        $this->assertEquals(5.0, $data['consumption_anomaly']['deviation_percent']);
    }

    public function test_flags_a_consumption_anomaly_above_the_default_threshold(): void
    {
        $this->seedConsumption(40);

        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('attention', $data['status']);
        $this->assertSame('anomaly', $data['consumption_anomaly']['status']);
        $this->assertEquals(100.0, $data['consumption_anomaly']['deviation_percent']);
        $this->assertEquals(20.0, $data['consumption_anomaly']['average_kwh']);
        $this->assertCount(1, $data['findings']);
        $this->assertStringContainsString('05.11.2026', $data['findings'][0]);
    }

    public function test_respects_the_configured_threshold(): void
    {
        $this->seedConsumption(40);
        Setting::set('anomaly_threshold_percent', 150);

        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('ok', $data['consumption_anomaly']['status']);
        $this->assertEquals(150.0, $data['consumption_anomaly']['threshold_percent']);
    }

    public function test_lower_than_average_consumption_is_not_an_anomaly(): void
    {
        $this->seedConsumption(5);

        $this->assertSame('ok', $this->callTool(CheckAnomalies::class)['consumption_anomaly']['status']);
    }

    public function test_flags_a_sync_outage(): void
    {
        $this->apiLogFor('2026-11-03', ['power_total_kwh' => 20], time: '08:00');

        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('attention', $data['status']);
        $this->assertSame('outage', $data['sync_outage']['status']);
        $this->assertSame(76, $data['sync_outage']['gap_hours']);
        $this->assertSame(24, $data['sync_outage']['threshold_hours']);
    }

    public function test_reports_insufficient_data_without_history(): void
    {
        $this->apiLogFor('2026-11-05', ['power_total_kwh' => 20]);
        $this->apiLogFor('2026-11-06', ['power_total_kwh' => 4], time: '11:45');

        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('insufficient_data', $data['status']);
        $this->assertSame('insufficient_data', $data['consumption_anomaly']['status']);
        $this->assertSame(0, $data['consumption_anomaly']['comparison_days']);
    }

    public function test_reports_no_data_on_an_empty_database(): void
    {
        $data = $this->callTool(CheckAnomalies::class);

        $this->assertSame('insufficient_data', $data['status']);
        $this->assertSame('no_data', $data['sync_outage']['status']);
        $this->assertSame('no_value', $data['consumption_anomaly']['status']);
    }

    public function test_does_not_write_any_state(): void
    {
        $this->seedConsumption(40);

        $this->callTool(CheckAnomalies::class);

        $this->assertNull(Setting::get('last_anomaly_warning_date'));
        $this->assertNull(Setting::get('last_outage_warning_date'));
    }
}
