<?php

namespace Tests\Feature;

use App\Filament\Pages\Evaluation\ChangeImpactPage;
use App\Filament\Pages\Evaluation\CompressorCyclingPage;
use App\Filament\Pages\Evaluation\CostOutlookPage;
use App\Filament\Pages\Evaluation\DataQualityPage;
use App\Filament\Pages\Evaluation\HotWaterPage;
use App\Filament\Pages\Evaluation\PeriodComparisonPage;
use App\Filament\Pages\Evaluation\TemperatureEfficiencyPage;
use App\Models\Annotation;
use App\Models\ApiLog;
use App\Models\User;
use App\Services\EvaluationService;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class EvaluationPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    public static function pages(): array
    {
        return [
            [PeriodComparisonPage::class], [ChangeImpactPage::class], [TemperatureEfficiencyPage::class],
            [HotWaterPage::class], [CostOutlookPage::class], [CompressorCyclingPage::class], [DataQualityPage::class],
        ];
    }

    /** @dataProvider pages */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_pages_render_without_data(string $page): void
    {
        $this->get($page::getUrl())->assertOk();
    }

    public function test_pages_render_with_data(): void
    {
        Annotation::create(['timestamp' => '2026-11-10 08:00:00', 'note' => 'Heizkurve geändert', 'category' => 'setting']);
        ApiLog::create(['timestamp' => '2026-11-15 06:00:00', 'day_index' => 0, 'power_total_kwh' => 20, 'power_heating_kwh' => 15, 'power_dhw_kwh' => 5, 'thermal_heating_kwh' => 60, 'thermal_dhw_kwh' => 15, 'outdoor_temperature' => 4, 'source' => 'sync']);

        foreach (self::pages() as [$page]) {
            $this->get($page::getUrl())->assertOk();
        }
    }

    public function test_temperature_classes_group_days(): void
    {
        $service = app(EvaluationService::class);
        $rows = [
            ['temp' => 3.1, 'cop' => 3.0, 'power_total' => 20, 'thermal' => 60, 'rod_kwh' => null],
            ['temp' => 3.9, 'cop' => 3.4, 'power_total' => 18, 'thermal' => 61, 'rod_kwh' => 1.0],
            ['temp' => 9.0, 'cop' => 4.5, 'power_total' => 8, 'thermal' => 36, 'rod_kwh' => null],
            ['temp' => null, 'cop' => 2.0, 'power_total' => 1, 'thermal' => 2, 'rod_kwh' => null],
        ];

        $classes = $service->temperatureClasses($rows);

        $this->assertCount(2, $classes);
        $this->assertSame(2, $classes[0]['days']);
        $this->assertSame(3.2, $classes[0]['avg_cop']);
        $this->assertSame(1.0, $classes[0]['rod_kwh']);
    }

    public function test_change_impact_limits_after_window_to_yesterday(): void
    {
        $impact = app(EvaluationService::class)->changeImpact(Carbon::parse('2026-11-18 10:00:00'), 7);

        $this->assertSame('2026-11-19', $impact['after']->to->toDateString());
        $this->assertSame(2, $impact['after_days']);
        $this->assertSame('2026-11-11', $impact['before']->from->toDateString());
        $this->assertSame('2026-11-17', $impact['before']->to->toDateString());
    }

    public function test_change_impact_today_has_no_after_days(): void
    {
        $impact = app(EvaluationService::class)->changeImpact(Carbon::parse('2026-11-20 08:00:00'), 7);

        $this->assertSame(0, $impact['after_days']);
    }

    public function test_daily_series_has_null_for_missing_values(): void
    {
        $period = new ReportPeriod(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-12'), 'none', 'custom');
        $series = app(EvaluationService::class)->dailySeries($period);

        $this->assertCount(3, $series);
        $this->assertNull($series[0]['power_total']);
        $this->assertNull($series[0]['cop']);
    }

    public function test_data_quality_detects_sync_gap(): void
    {
        foreach (['2026-11-10 06:00:00', '2026-11-10 07:00:00', '2026-11-12 07:00:00'] as $ts) {
            ApiLog::create(['timestamp' => $ts, 'day_index' => 0, 'power_total_kwh' => 5, 'source' => 'sync']);
        }

        $period = new ReportPeriod(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-12'), 'none', 'custom');
        $q = app(EvaluationService::class)->dataQuality($period, 6);

        $this->assertCount(1, $q['gaps']);
        $this->assertSame(48, $q['gaps'][0]['hours']);
    }

    public function test_all_widgets_render_with_data(): void
    {
        $a = Annotation::create(['timestamp' => '2026-11-10 08:00:00', 'note' => 'Heizkurve geändert', 'category' => 'setting']);
        ApiLog::create(['timestamp' => '2026-11-15 06:00:00', 'day_index' => 0, 'power_total_kwh' => 20, 'power_heating_kwh' => 15, 'power_dhw_kwh' => 5, 'thermal_heating_kwh' => 60, 'thermal_dhw_kwh' => 15, 'outdoor_temperature' => 4, 'source' => 'sync']);

        $widgets = collect(glob(app_path('Filament/Widgets/Evaluation/*.php')))
            ->map(fn ($f) => 'App\\Filament\\Widgets\\Evaluation\\' . basename($f, '.php'))
            ->reject(fn ($c) => (new \ReflectionClass($c))->isAbstract());

        $this->assertCount(13, $widgets);

        foreach ($widgets as $widget) {
            Livewire::test($widget, ['pageFilters' => ['preset' => 'last_30', 'compare' => 'previous', 'annotation' => $a->id, 'days' => '7']])
                ->assertOk();
        }
    }

    public function test_sums_are_not_compared_for_different_period_lengths(): void
    {
        $a = ['period' => ['days' => 3], 'app' => ['power_total_kwh' => 60.0, 'power_avg_per_day_kwh' => 20.0, 'power_days' => 3]];
        $b = ['period' => ['days' => 7], 'app' => ['power_total_kwh' => 100.0, 'power_avg_per_day_kwh' => 14.3, 'power_days' => 7]];

        $this->assertNotNull(\App\Support\MetricTable::sumsNote($a, $b));

        $rows = collect(\App\Support\MetricTable::rows($a, $b))->keyBy('label');

        $this->assertSame('–', $rows['Strom gesamt (App)']['delta']);
        $this->assertNotSame('–', $rows['Strom je Tag (App)']['delta']);
    }

    public function test_sums_are_compared_for_equal_complete_periods(): void
    {
        $a = ['period' => ['days' => 7], 'app' => ['power_total_kwh' => 100.0, 'power_days' => 7]];
        $b = ['period' => ['days' => 7], 'app' => ['power_total_kwh' => 80.0, 'power_days' => 7]];

        $this->assertNull(\App\Support\MetricTable::sumsNote($a, $b));
        $this->assertSame('+20,0 kWh', \App\Support\MetricTable::rows($a, $b)[0]['delta']);
    }
}
