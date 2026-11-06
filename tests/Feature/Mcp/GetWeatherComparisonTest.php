<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetWeatherComparison;
use App\Models\Setting;
use App\Models\WeatherForecastLog;
use App\Models\WeatherLog;
use Illuminate\Support\Carbon;

class GetWeatherComparisonTest extends McpTestCase
{
    private function configureLocation(): void
    {
        Setting::set('weather_latitude', 52.0);
        Setting::set('weather_longitude', 13.0);
    }

    private function archiveDay(string $date, float $temperature, float $humidity = 80): void
    {
        WeatherLog::create([
            'timestamp' => Carbon::parse($date.' 00:00:00', 'Europe/Berlin'),
            'temperature' => $temperature,
            'humidity' => $humidity,
            'source' => 'archive_daily',
        ]);
    }

    public function test_returns_an_error_without_a_configured_location(): void
    {
        // Die Migration legt einen Standort an, daher hier bewusst entfernen
        Setting::set('weather_latitude', null);
        Setting::set('weather_longitude', null);

        $this->rawToolResponse(GetWeatherComparison::class)->assertHasErrors();
    }

    public function test_compares_plant_and_external_temperature_per_day(): void
    {
        $this->configureLocation();
        $this->archiveDay('2026-11-04', 6.0);
        $this->archiveDay('2026-11-05', 4.0);
        $this->apiLogFor('2026-11-04', ['outdoor_temp' => 7.0]);

        $data = $this->callTool(GetWeatherComparison::class, ['days' => 7]);
        $byDate = collect($data['daily'])->keyBy('date');

        $this->assertSame('2026-10-30', $data['from']);
        $this->assertSame('2026-11-05', $data['to']);

        $this->assertEquals(6.0, $byDate['2026-11-04']['external_temp_celsius']);
        $this->assertEquals(7.0, $byDate['2026-11-04']['plant_temp_celsius']);
        $this->assertEquals(1.0, $byDate['2026-11-04']['deviation_celsius']);

        // Kein Anlagenwert an diesem Tag: null statt 0
        $this->assertNull($byDate['2026-11-05']['plant_temp_celsius']);
        $this->assertNull($byDate['2026-11-05']['deviation_celsius']);

        $this->assertSame(2, $data['summary']['days_with_external_data']);
        $this->assertSame(1, $data['summary']['days_with_plant_data']);
        $this->assertEquals(1.0, $data['summary']['mean_deviation_celsius']);
    }

    public function test_lists_the_latest_forecast_per_day_within_the_horizon(): void
    {
        $this->configureLocation();
        Setting::set('forecast_horizon_days', 2);

        foreach (['2026-11-06', '2026-11-07', '2026-11-08'] as $i => $date) {
            WeatherForecastLog::create([
                'fetched_at' => now()->subHours(6),
                'forecast_for' => Carbon::parse($date.' 00:00:00', 'Europe/Berlin'),
                'forecast_temperature' => 3 + $i,
                'forecast_humidity' => 70,
            ]);
        }

        // Neuerer Abruf für denselben Tag überschreibt den älteren
        WeatherForecastLog::create([
            'fetched_at' => now()->subHour(),
            'forecast_for' => Carbon::parse('2026-11-07 00:00:00', 'Europe/Berlin'),
            'forecast_temperature' => 9,
            'forecast_humidity' => 60,
        ]);

        $data = $this->callTool(GetWeatherComparison::class);

        $this->assertCount(2, $data['forecast']);
        $this->assertSame('2026-11-06', $data['forecast'][0]['date']);
        $this->assertSame('2026-11-07', $data['forecast'][1]['date']);
        $this->assertEquals(9.0, $data['forecast'][1]['temp_celsius']);
    }

    public function test_validates_day_count(): void
    {
        $this->configureLocation();

        $this->rawToolResponse(GetWeatherComparison::class, ['days' => 0])->assertHasErrors();
        $this->rawToolResponse(GetWeatherComparison::class, ['days' => 91])->assertHasErrors();
    }
}
