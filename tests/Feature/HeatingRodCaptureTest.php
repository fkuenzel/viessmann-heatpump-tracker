<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Services\ViessmannApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HeatingRodCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_status_reads_heating_rod_features(): void
    {
        $client = Mockery::mock(ViessmannApiClient::class)->makePartial();
        $client->shouldReceive('listFeaturesCached')->andReturn([
            ['feature' => 'heating.heatingRod', 'properties' => ['active' => ['value' => true]]],
            ['feature' => 'heating.heatingRod.power.consumption.summary.heating', 'properties' => ['currentDay' => ['value' => 1.5]]],
            ['feature' => 'heating.heatingRod.power.consumption.summary.dhw', 'properties' => ['currentDay' => ['value' => 0.25]]],
            ['feature' => 'heating.heatingRod.statistics', 'properties' => ['starts' => ['value' => 7], 'hours' => ['value' => 3.5]]],
        ]);

        $status = $client->getDeviceStatus();

        $this->assertTrue($status['heating_rod_active']);
        $this->assertSame(1.5, $status['heating_rod_heating_kwh']);
        $this->assertSame(0.25, $status['heating_rod_dhw_kwh']);
        $this->assertSame(7, $status['heating_rod_starts']);
        $this->assertSame(3.5, $status['heating_rod_hours']);
    }

    public function test_missing_heating_rod_features_yield_null_not_zero(): void
    {
        $client = Mockery::mock(ViessmannApiClient::class)->makePartial();
        $client->shouldReceive('listFeaturesCached')->andReturn([]);

        $status = $client->getDeviceStatus();

        $this->assertNull($status['heating_rod_heating_kwh']);
        $this->assertNull($status['heating_rod_starts']);
        $this->assertNull($status['heating_rod_active']);
    }

    public function test_heating_rod_values_are_persisted_and_cast(): void
    {
        $log = ApiLog::create([
            'timestamp' => now(),
            'source' => 'manual',
            'day_index' => 0,
            'measured_at' => now(),
            'heating_rod_heating_kwh' => 1.234,
            'heating_rod_starts' => 3,
            'heating_rod_active' => false,
        ])->fresh();

        $this->assertSame('1.23', $log->heating_rod_heating_kwh);
        $this->assertSame(3, $log->heating_rod_starts);
        $this->assertFalse($log->heating_rod_active);
        $this->assertNull($log->heating_rod_dhw_kwh);
    }
}
