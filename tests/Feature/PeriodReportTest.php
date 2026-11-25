<?php

namespace Tests\Feature;

use App\Enums\MeterReadingReason;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PeriodAppKpiWidget;
use App\Filament\Widgets\PeriodMeterKpiWidget;
use App\Models\ApiLog;
use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\PeriodReportService;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PeriodReportTest extends TestCase
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

    private function day(string $date, float $heating, float $dhw, float $thermalHeating, float $thermalDhw, ?float $temp = null): void
    {
        $day = Carbon::parse($date);

        ApiLog::create([
            'timestamp' => $day->copy()->addDay()->setTime(6, 0),
            'source' => 'scheduled',
            'day_index' => 1,
            'measured_at' => $day->copy()->startOfDay(),
            'power_heating_kwh' => $heating,
            'power_dhw_kwh' => $dhw,
            'power_total_kwh' => $heating + $dhw,
            'thermal_heating_kwh' => $thermalHeating,
            'thermal_dhw_kwh' => $thermalDhw,
            'outdoor_temp' => $temp,
        ]);
    }

    private function seedWeek(): void
    {
        // 7 Tage 18.-24.11.: je 10 kWh Strom (9 Heizen/1 WW), 40 kWh Waerme -> COP 4,0
        for ($d = 18; $d <= 24; $d++) {
            $this->day("2026-11-{$d}", 9, 1, 36, 4);
        }
    }

    public function test_presets_resolve_expected_ranges(): void
    {
        $p = ReportPeriod::forPreset('last_7');
        $this->assertSame('2026-11-18', $p->from->toDateString());
        $this->assertSame('2026-11-24', $p->to->toDateString());
        $this->assertSame(7, $p->dayCount());
        $this->assertFalse($p->includesToday());

        $m = ReportPeriod::forPreset('this_month');
        $this->assertSame('2026-11-01', $m->from->toDateString());
        $this->assertTrue($m->includesToday());

        $l = ReportPeriod::forPreset('last_month');
        $this->assertSame('2026-10-01', $l->from->toDateString());
        $this->assertSame('2026-10-31', $l->to->toDateString());
    }

    public function test_comparison_ranges(): void
    {
        $p = ReportPeriod::forPreset('last_7');
        $this->assertSame('2026-11-11', $p->comparisonFrom()->toDateString());
        $this->assertSame('2026-11-17', $p->comparisonTo()->toDateString());

        $y = ReportPeriod::forPreset('last_7', compare: 'previous_year');
        $this->assertSame('2025-11-18', $y->comparisonFrom()->toDateString());
        $this->assertSame('2025-11-24', $y->comparisonTo()->toDateString());

        $n = ReportPeriod::forPreset('last_7', compare: 'none');
        $this->assertNull($n->comparison());
        $this->assertTrue($n->comparisonTo()->lessThan($n->comparisonFrom()));
    }

    public function test_custom_range_swaps_reversed_dates_and_unknown_filters_fall_back_to_30_days(): void
    {
        $c = ReportPeriod::forPreset('custom', '2026-11-10', '2026-11-05');
        $this->assertSame('2026-11-05', $c->from->toDateString());

        // Alte Sitzungsfilter (Format "days") werden ignoriert: Standard 30 Tage.
        $legacy = ReportPeriod::fromFilters(['days' => '90']);
        $this->assertSame('last_30', $legacy->preset);
    }

    public function test_app_values_are_summed_day_exact(): void
    {
        $this->seedWeek();

        $report = app(PeriodReportService::class)->report(ReportPeriod::forPreset('last_7'));

        $this->assertSame(70.0, $report['app']['power_total_kwh']);
        $this->assertSame(63.0, $report['app']['power_heating_kwh']);
        $this->assertSame(280.0, $report['app']['thermal_total_kwh']);
        $this->assertSame(4.0, $report['app']['cop']);
        $this->assertSame(10.0, $report['app']['power_avg_per_day_kwh']);
    }

    public function test_meter_values_only_count_fully_contained_intervals(): void
    {
        $this->seedWeek();

        MeterReading::create(['timestamp' => '2026-11-10 08:00', 'counter_ht' => 1000, 'counter_nt' => 0, 'reason' => MeterReadingReason::StartValue]);
        MeterReading::create(['timestamp' => '2026-11-18 00:00', 'counter_ht' => 1080, 'counter_nt' => 0]);
        MeterReading::create(['timestamp' => '2026-11-22 00:00', 'counter_ht' => 1120, 'counter_nt' => 0]);
        MeterReading::create(['timestamp' => '2026-11-26 00:00', 'counter_ht' => 1160, 'counter_nt' => 0]);

        $report = app(PeriodReportService::class)->report(ReportPeriod::forPreset('last_7'));

        // Nur 18.11.-22.11. liegt komplett im Zeitraum 18.-24.11.
        $this->assertSame(40.0, $report['meter']['consumption_kwh']);
        $this->assertSame(1, $report['meter']['intervals']);
        $this->assertEqualsWithDelta(4.0, $report['meter']['coverage_days'], 0.01);
        $this->assertStringContainsString('4 von 7 Tagen', $report['meter']['coverage_label']);
    }

    public function test_cost_is_taken_from_meter_intervals_and_extrapolated_on_partial_coverage(): void
    {
        $this->seedWeek();
        PriceHistory::create([
            'valid_from' => '2026-01-01',
            'provider_name' => 'Test',
            'tariff_name' => 'Test',
            'base_fee' => 0,
            'base_fee_period' => 'yearly',
            'ht_price' => 0.25,
            'nt_price' => 0.25,
        ]);

        MeterReading::create(['timestamp' => '2026-11-18 00:00', 'counter_ht' => 1000, 'counter_nt' => 0, 'reason' => MeterReadingReason::StartValue]);
        MeterReading::create(['timestamp' => '2026-11-22 00:00', 'counter_ht' => 1040, 'counter_nt' => 0]);

        $meter = app(PeriodReportService::class)->report(ReportPeriod::forPreset('last_7'))['meter'];

        $this->assertEqualsWithDelta(10.0, $meter['cost_eur'], 0.01);
        $this->assertEqualsWithDelta(17.5, $meter['cost_extrapolated_eur'], 0.1);
    }

    public function test_comparison_deltas_are_computed(): void
    {
        $this->seedWeek();
        for ($d = 11; $d <= 17; $d++) {
            $this->day("2026-11-{$d}", 4, 1, 20, 0);
        }

        $data = app(PeriodReportService::class)->reportWithComparison(ReportPeriod::forPreset('last_7'));

        $delta = $data['deltas']['app.power_total_kwh'];
        $this->assertSame(70.0, $delta['value']);
        $this->assertSame(35.0, $delta['previous']);
        $this->assertSame(100.0, $delta['delta_percent']);
        $this->assertArrayNotHasKey('app.power_days', $data['deltas']);
    }

    public function test_heating_degree_days_use_outdoor_temperature(): void
    {
        // Messung am laufenden Tag (day_index 0) liefert die Aussentemperatur.
        foreach ([18, 19] as $d) {
            ApiLog::create([
                'timestamp' => Carbon::parse("2026-11-{$d} 12:00"),
                'source' => 'scheduled',
                'day_index' => 0,
                'measured_at' => Carbon::parse("2026-11-{$d} 12:00"),
                'power_heating_kwh' => 10,
                'power_dhw_kwh' => 0,
                'power_total_kwh' => 10,
                'outdoor_temp' => 5,
            ]);
        }

        $weather = app(PeriodReportService::class)->report(ReportPeriod::forPreset('last_7'))['weather'];

        $this->assertSame(5.0, $weather['avg_temperature_celsius']);
        $this->assertSame(30.0, $weather['heating_degree_days']);
        $this->assertSame(0.67, $weather['heating_kwh_per_degree_day']);
    }

    public function test_dashboard_and_kpi_widgets_render_with_filters(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seedWeek();

        Livewire::test(Dashboard::class)->assertSuccessful();

        foreach (['last_7', 'custom', 'all'] as $preset) {
            $filters = ['preset' => $preset, 'from' => '2026-11-18', 'to' => '2026-11-24', 'compare' => 'previous'];

            Livewire::test(PeriodAppKpiWidget::class, ['pageFilters' => $filters])
                ->assertSuccessful()
                ->assertSee('Strom (App)');
            Livewire::test(PeriodMeterKpiWidget::class, ['pageFilters' => $filters])
                ->assertSuccessful()
                ->assertSee('Verbrauch (Zähler)');
        }
    }

    public function test_all_dashboard_widgets_render_for_every_preset_and_compare_mode(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seedWeek();
        \App\Models\Annotation::create(['timestamp' => '2026-11-20 10:00', 'category' => 'other', 'note' => 'Test']);
        MeterReading::create(['timestamp' => '2026-11-18 00:00', 'counter_ht' => 1000, 'counter_nt' => 0, 'reason' => MeterReadingReason::StartValue]);
        MeterReading::create(['timestamp' => '2026-11-22 00:00', 'counter_ht' => 1040, 'counter_nt' => 0]);

        $widgets = collect(glob(app_path('Filament/Widgets/*Widget.php')))
            ->map(fn (string $file) => 'App\\Filament\\Widgets\\' . basename($file, '.php'))
            ->filter(fn (string $class) => class_exists($class) && ! (new \ReflectionClass($class))->isAbstract());

        $this->assertGreaterThan(15, $widgets->count());

        foreach (['last_7', 'this_month', 'last_year', 'all'] as $preset) {
            foreach (['previous', 'previous_year', 'none'] as $compare) {
                foreach ($widgets as $class) {
                    try {
                        Livewire::test($class, ['pageFilters' => ['preset' => $preset, 'compare' => $compare]])
                            ->assertSuccessful();
                    } catch (\Throwable $e) {
                        $this->fail("{$class} ({$preset}/{$compare}): " . $e->getMessage());
                    }
                }
            }
        }
    }
}
