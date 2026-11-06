<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetCopTrend;

class GetCopTrendTest extends McpTestCase
{
    public function test_lists_days_with_data_and_counts_days_without(): void
    {
        $this->seedMeterInterval();
        // Zusätzlich 05.11. mit Thermik: 18 kWh Strom, 54 kWh Thermik => COP 3,0
        $this->apiLogFor('2026-11-05', ['power_total_kwh' => 18, 'thermal_heating_kwh' => 54]);

        $data = $this->callTool(GetCopTrend::class, ['days' => 7]);

        $this->assertSame('2026-10-30', $data['from']);
        $this->assertSame('2026-11-05', $data['to']);

        $byDate = collect($data['series'])->keyBy('date');

        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], $byDate->keys()->all());

        // 03.11.: 75 kWh Thermik / 20 kWh Strom = 3,75; Faktor 1,1 => 3,41
        $this->assertEquals(3.75, $byDate['2026-11-03']['cop_app']);
        $this->assertEquals(3.41, $byDate['2026-11-03']['cop_real_estimate']);
        $this->assertEquals(1.1, $byDate['2026-11-03']['correction_factor']);

        // 01.11.: nur Strom, keine Thermik => kein COP, aber Stromwert sichtbar
        $this->assertNull($byDate['2026-11-01']['cop_app']);
        $this->assertEquals(20.0, $byDate['2026-11-01']['power_app_kwh']);

        // 05.11. liegt außerhalb des Intervalls (Ende 05.11. 00:00 Uhr = Intervalltag)
        $this->assertEquals(3.0, $byDate['2026-11-05']['cop_app']);

        $this->assertSame(2, $data['summary']['days_without_any_data']);
        $this->assertSame(2, $data['summary']['period_days_used']);
        // Summe Thermik 75 + 54 = 129, Summe Strom 20 + 18 = 38 => 3,395 (forRange rundet auf 3 Stellen) => 3,4
        $this->assertEquals(3.4, $data['summary']['period_cop_app']);
        // Mittelwert der Tages-COPs (3,75 + 3,0) / 2 = 3,375 => 3,38
        $this->assertEquals(3.38, $data['summary']['mean_of_daily_cop_app']);
    }

    public function test_reports_todays_partial_cop_separately(): void
    {
        $this->apiLogFor('2026-11-06', ['power_total_kwh' => 5, 'thermal_heating_kwh' => 20], time: '11:30');

        $data = $this->callTool(GetCopTrend::class, ['days' => 3]);

        $this->assertSame('2026-11-06', $data['today_so_far']['date']);
        $this->assertEquals(4.0, $data['today_so_far']['cop_app']);
        $this->assertSame([], $data['series']);
    }

    public function test_validates_day_count(): void
    {
        $this->rawToolResponse(GetCopTrend::class, ['days' => 0])->assertHasErrors();
        $this->rawToolResponse(GetCopTrend::class, ['days' => 91])->assertHasErrors();
        $this->rawToolResponse(GetCopTrend::class, ['days' => 90])->assertHasNoErrors();
    }
}
