<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\ConfigLog;
use App\Models\User;
use App\Services\ConfigChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConfigChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConfigChangeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConfigChangeService;
    }

    /**
     * @param  array<string, mixed>  $overrides  Feature-Name => Property => Wert
     * @return array<int, array<string, mixed>>
     */
    private function features(array $overrides = []): array
    {
        $defaults = [
            'heating.circuits.0.heating.curve' => ['slope' => 1.2, 'shift' => 0],
            'heating.dhw.temperature.main' => ['value' => 45],
            'heating.operating.programs.holiday' => ['active' => false, 'start' => '2000-01-01', 'end' => '2000-01-01'],
            'heating.dhw.schedule' => ['active' => true, 'entries' => [
                'mon' => [['mode' => 'on', 'start' => '06:00', 'end' => '08:30', 'position' => 0]],
                'tue' => [['mode' => 'on', 'start' => '06:00', 'end' => '08:30', 'position' => 0]],
            ]],
        ];

        $merged = array_replace($defaults, $overrides);

        return collect($merged)
            ->map(fn (array $properties, string $feature): array => [
                'feature' => $feature,
                'properties' => collect($properties)
                    ->map(fn (mixed $value): array => ['type' => 'x', 'value' => $value])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function test_first_run_creates_baseline_without_annotations(): void
    {
        $changes = $this->service->capture($this->features());

        $this->assertSame(0, $changes);
        $this->assertSame(4, ConfigLog::count());
        $this->assertSame(4, ConfigLog::where('is_baseline', true)->count());
        $this->assertSame(0, Annotation::count());
    }

    public function test_unchanged_values_write_nothing(): void
    {
        $this->service->capture($this->features());
        $changes = $this->service->capture($this->features());

        $this->assertSame(0, $changes);
        $this->assertSame(4, ConfigLog::count());
        $this->assertSame(0, Annotation::count());
    }

    public function test_float_and_int_representations_are_treated_as_equal(): void
    {
        $this->service->capture($this->features());

        $changes = $this->service->capture($this->features([
            'heating.circuits.0.heating.curve' => ['slope' => 1.2, 'shift' => 0.0],
        ]));

        $this->assertSame(0, $changes);
    }

    public function test_change_creates_log_row_and_german_annotation(): void
    {
        $this->service->capture($this->features());

        $now = Carbon::parse('2026-11-07 10:15:00');
        $changes = $this->service->capture($this->features([
            'heating.circuits.0.heating.curve' => ['slope' => 1.0, 'shift' => 2],
        ]), $now);

        $this->assertSame(1, $changes);

        $row = ConfigLog::where('feature', 'heating.circuits.0.heating.curve')
            ->where('is_baseline', false)
            ->sole();
        $this->assertEquals(['shift' => 2, 'slope' => 1.0], $row->value);
        $this->assertEquals(['shift' => 0, 'slope' => 1.2], $row->previous_value);

        $annotation = Annotation::sole();
        $this->assertSame('einstellung', $annotation->category);
        $this->assertTrue($annotation->timestamp->equalTo($now));
        $this->assertSame(
            'Heizkurve geändert: Neigung von 1,2 auf 1, Niveau von 0 auf 2 (automatisch erfasst)',
            $annotation->note
        );
    }

    public function test_mode_changes_such_as_holiday_program_are_recorded(): void
    {
        $this->service->capture($this->features());

        $this->service->capture($this->features([
            'heating.operating.programs.holiday' => ['active' => true, 'start' => '2026-11-10', 'end' => '2026-11-17'],
        ]));

        $this->assertSame(
            'Ferienprogramm geändert: Aktiv von aus auf ein, Start von 01.01.2000 auf 10.11.2026, Ende von 01.01.2000 auf 17.11.2026 (automatisch erfasst)',
            Annotation::sole()->note
        );
    }

    public function test_schedule_change_names_changed_days(): void
    {
        $this->service->capture($this->features());

        $this->service->capture($this->features([
            'heating.dhw.schedule' => ['active' => true, 'entries' => [
                'mon' => [['mode' => 'on', 'start' => '05:00', 'end' => '08:30', 'position' => 0]],
                'tue' => [['mode' => 'on', 'start' => '06:00', 'end' => '08:30', 'position' => 0]],
            ]],
        ]));

        $this->assertStringContainsString('Zeitprogramm Warmwasser geändert: Zeiten angepasst (Mo)', Annotation::sole()->note);
    }

    public function test_missing_features_are_skipped(): void
    {
        $changes = $this->service->capture([]);

        $this->assertSame(0, $changes);
        $this->assertSame(0, ConfigLog::count());
    }

    public function test_schedule_is_formatted_compactly(): void
    {
        $text = ConfigChangeService::formatSchedule([
            'mon' => [['mode' => 'normal', 'start' => '06:00', 'end' => '22:00']],
            'tue' => [['mode' => 'normal', 'start' => '06:00', 'end' => '22:00']],
            'wed' => [],
        ]);

        $this->assertSame('Mo, Di: 06:00-22:00 normal; Mi: keine Zeiten', $text);
    }

    public function test_config_log_page_renders_for_logged_in_user(): void
    {
        $this->service->capture($this->features());
        $this->service->capture($this->features([
            'heating.dhw.temperature.main' => ['value' => 50],
        ]));

        $this->actingAs(User::factory()->create())
            ->get('/admin/config-logs')
            ->assertOk()
            ->assertSee('Konfigurationsverlauf');
    }
}
