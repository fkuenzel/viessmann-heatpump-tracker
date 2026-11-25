<?php

namespace Tests\Feature;

use App\Enums\MeterReadingReason;
use App\Models\ApiLog;
use App\Models\MeterReading;
use App\Services\MeterIntervalBreakdownService;
use App\Services\MeterReadingPlausibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeterReadingReasonTest extends TestCase
{
    use RefreshDatabase;

    private function reading(string $ts, float $ht, float $nt, MeterReadingReason $reason = MeterReadingReason::Regular): MeterReading
    {
        return MeterReading::create([
            'timestamp' => Carbon::parse($ts),
            'counter_ht' => $ht,
            'counter_nt' => $nt,
            'reason' => $reason,
        ]);
    }

    private function dayRow(string $date, float $total): void
    {
        $ts = Carbon::parse($date)->addDay()->setTime(6, 0);

        ApiLog::create([
            'timestamp' => $ts,
            'source' => 'scheduled',
            'day_index' => 1,
            'measured_at' => Carbon::parse($date)->startOfDay(),
            'power_total_kwh' => $total,
            'power_heating_kwh' => $total,
            'power_dhw_kwh' => 0,
        ]);
    }

    public function test_default_reason_is_regular_and_enum_is_cast(): void
    {
        $reading = MeterReading::create([
            'timestamp' => now(),
            'counter_ht' => 100,
            'counter_nt' => 50,
        ])->fresh();

        $this->assertSame(MeterReadingReason::Regular, $reading->reason);
        $this->assertSame('manual', $reading->source);
    }

    public function test_meter_replacement_starts_a_new_chain_without_interval(): void
    {
        $this->reading('2026-11-01 08:00', 1000, 500, MeterReadingReason::StartValue);
        $this->reading('2026-11-05 08:00', 1040, 520);
        $this->reading('2026-11-06 08:00', 3, 1, MeterReadingReason::MeterReplacement);
        $this->reading('2026-11-08 08:00', 50, 20);

        $pairs = MeterReading::intervalPairs();

        $this->assertCount(2, $pairs);
        $this->assertSame('2026-11-05', $pairs[0][1]->timestamp->toDateString());
        $this->assertSame('2026-11-08', $pairs[1][1]->timestamp->toDateString());
        $this->assertSame(
            ['2026-11-05', '2026-11-08'],
            MeterReading::query()->intervalEnds()->orderBy('timestamp')->get()->map(fn ($r) => $r->timestamp->toDateString())->all(),
        );
    }

    public function test_estimate_makes_interval_unreliable(): void
    {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $day) {
            $this->dayRow($day, 10);
        }

        $this->reading('2026-11-01 00:00', 1000, 500, MeterReadingReason::StartValue);
        $this->reading('2026-11-03 00:00', 1010, 510, MeterReadingReason::Estimate);
        $this->reading('2026-11-04 23:59', 1020, 520);

        $service = app(MeterIntervalBreakdownService::class);
        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $first = $service->breakdown($readings[1]);
        $second = $service->breakdown($readings[2]);

        $this->assertTrue($first['estimated']);
        $this->assertFalse($first['reliable']);
        $this->assertTrue($second['estimated']);
        $this->assertFalse($second['reliable']);
    }

    public function test_plausibility_rejects_decreasing_counters(): void
    {
        $this->reading('2026-11-01 08:00', 1000, 500, MeterReadingReason::StartValue);
        $this->reading('2026-11-10 08:00', 1100, 600);

        $service = app(MeterReadingPlausibilityService::class);

        $this->assertNotEmpty($service->errors(Carbon::parse('2026-11-05 08:00'), 990, 520, MeterReadingReason::Regular));
        $this->assertNotEmpty($service->errors(Carbon::parse('2026-11-05 08:00'), 1050, 620, MeterReadingReason::Regular));
        $this->assertSame([], $service->errors(Carbon::parse('2026-11-05 08:00'), 1050, 550, MeterReadingReason::Regular));
    }

    public function test_plausibility_allows_free_counters_for_meter_replacement(): void
    {
        $this->reading('2026-11-01 08:00', 1000, 500, MeterReadingReason::StartValue);

        $errors = app(MeterReadingPlausibilityService::class)
            ->errors(Carbon::parse('2026-11-05 08:00'), 2, 1, MeterReadingReason::MeterReplacement);

        $this->assertSame([], $errors);
    }

    public function test_plausibility_ignores_the_edited_record_itself(): void
    {
        $this->reading('2026-11-01 08:00', 1000, 500, MeterReadingReason::StartValue);
        $edited = $this->reading('2026-11-05 08:00', 1050, 550);

        $errors = app(MeterReadingPlausibilityService::class)
            ->errors($edited->timestamp, 1060, 560, MeterReadingReason::Regular, $edited->id);

        $this->assertSame([], $errors);
    }

    public function test_warning_for_implausible_correction_factor(): void
    {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->dayRow($day, 10);
        }

        $this->reading('2026-11-01 00:00', 1000, 500, MeterReadingReason::StartValue);
        // Zaehler 60 kWh in 3 Tagen, App 30 kWh -> Faktor 2,0
        $reading = $this->reading('2026-11-04 00:00', 1060, 500);

        $warnings = app(MeterReadingPlausibilityService::class)->warnings($reading);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Korrekturfaktor', $warnings[0]);
    }

    public function test_migration_marks_oldest_reading_as_start_value(): void
    {
        $this->assertSame(
            MeterReadingReason::StartValue,
            MeterReadingReason::from('start_value'),
        );
        $this->assertTrue(MeterReadingReason::StartValue->startsNewChain());
        $this->assertTrue(MeterReadingReason::MeterReplacement->startsNewChain());
        $this->assertFalse(MeterReadingReason::Regular->startsNewChain());
        $this->assertTrue(MeterReadingReason::Estimate->isEstimate());
    }
}
