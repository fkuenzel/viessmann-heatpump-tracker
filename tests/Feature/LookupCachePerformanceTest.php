<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\WeatherLog;
use App\Services\PeriodReportService;
use App\Services\TariffLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tarife und Wetter-Tagesmittel werden pro Request einmal geladen
 * (vorher je Tag bzw. je Ablese-Intervall eine Abfrage).
 */
class LookupCachePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function tariff(string $validFrom, float $ht): PriceHistory
    {
        return PriceHistory::create([
            'valid_from' => $validFrom,
            'provider_name' => 'Test',
            'tariff_name' => 'Test',
            'base_fee' => 10,
            'base_fee_period' => 'monthly',
            'ht_price' => $ht,
            'nt_price' => $ht,
        ]);
    }

    public function test_tariff_lookup_resolves_validity_and_changes_in_memory(): void
    {
        $this->tariff('2026-01-01', 0.30);
        $this->tariff('2026-06-01', 0.35);

        $lookup = app(TariffLookup::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertNull($lookup->validAt(Carbon::parse('2025-12-31')));
        $this->assertEquals(0.30, (float) $lookup->validAt(Carbon::parse('2026-05-31 23:59:59'))->ht_price);
        $this->assertEquals(0.35, (float) $lookup->validAt(Carbon::parse('2026-06-01 00:00:00'))->ht_price);
        $this->assertTrue($lookup->changedBetween(Carbon::parse('2026-05-01'), Carbon::parse('2026-06-15')));
        $this->assertFalse($lookup->changedBetween(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01')));
        $this->assertTrue($lookup->any());

        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_tariff_lookup_is_invalidated_on_save_and_delete(): void
    {
        $lookup = app(TariffLookup::class);
        $this->assertFalse($lookup->any());

        $tariff = $this->tariff('2026-01-01', 0.30);
        $this->assertTrue($lookup->any());
        $this->assertEquals(0.30, (float) PriceHistory::validAt(Carbon::parse('2026-02-01'))->ht_price);

        $tariff->update(['ht_price' => 0.40]);
        $this->assertEquals(0.40, (float) PriceHistory::validAt(Carbon::parse('2026-02-01'))->ht_price);

        $tariff->delete();
        $this->assertFalse($lookup->any());
        $this->assertNull(PriceHistory::validAt(Carbon::parse('2026-02-01')));
    }

    public function test_weather_day_averages_use_one_query_and_are_invalidated(): void
    {
        foreach ([['2026-11-10 06:00:00', 4.0], ['2026-11-10 18:00:00', 8.0], ['2026-11-11 12:00:00', 1.0]] as [$ts, $t]) {
            WeatherLog::create(['timestamp' => $ts, 'temperature' => $t, 'humidity' => 70, 'source' => 'live_snapshot']);
        }

        $reports = app(PeriodReportService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertEquals(6.0, $reports->averageTemperature(Carbon::parse('2026-11-10')));
        $this->assertEquals(1.0, $reports->averageTemperature(Carbon::parse('2026-11-11')));
        $this->assertNull($reports->averageTemperature(Carbon::parse('2026-11-12')));

        $weatherQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'weather_log'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $weatherQueries);

        WeatherLog::create(['timestamp' => '2026-11-12 12:00:00', 'temperature' => 3.0, 'humidity' => 70, 'source' => 'live_snapshot']);
        $this->assertEquals(3.0, $reports->averageTemperature(Carbon::parse('2026-11-12')));
    }
}
