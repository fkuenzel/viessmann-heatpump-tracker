<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\HeatpumpServer;
use App\Models\ApiLog;
use App\Models\MeterReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Basis für die MCP-Tool-Tests: friert die Zeit auf einen festen Tag ein
 * (Freitag, 06.11.2026, 12:00 Uhr Ortszeit) und stellt kleine Helfer für
 * Testdaten und den Tool-Aufruf bereit.
 */
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Ruft ein Tool über den Server auf und liefert dessen strukturierte
     * Antwort als Array (null bei Fehlerantwort ohne strukturierten Inhalt).
     *
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    protected function callTool(string $tool, array $arguments = []): ?array
    {
        $response = $this->rawToolResponse($tool, $arguments);

        return (fn (): ?array => $this->structuredContent())->call($response);
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    protected function rawToolResponse(string $tool, array $arguments = []): TestResponse
    {
        return HeatpumpServer::tool($tool, $arguments);
    }

    /**
     * Legt eine api_log-Zeile an, die sich auf $date bezieht (Sync-Zeitpunkt
     * am selben Tag, 23:45 Uhr, day_index 0 - der Normalfall des Syncs).
     *
     * @param  array<string, mixed>  $values
     */
    protected function apiLogFor(string $date, array $values, string $source = 'scheduled', string $time = '23:45'): ApiLog
    {
        $timestamp = Carbon::parse($date . ' ' . $time, 'Europe/Berlin');

        return ApiLog::create(array_merge([
            'timestamp' => $timestamp,
            'source' => $source,
            'day_index' => 0,
            'measured_at' => $timestamp,
        ], $values));
    }

    protected function meterReading(string $timestamp, float $ht, float $nt): MeterReading
    {
        return MeterReading::create([
            'timestamp' => Carbon::parse($timestamp, 'Europe/Berlin'),
            'meter_number' => '1234',
            'counter_ht' => $ht,
            'counter_nt' => $nt,
        ]);
    }

    /**
     * Zwei Zählerstände (01.11. und 05.11., jeweils 00:00 Uhr) mit 80 kWh HT
     * und 8 kWh NT Differenz, dazu App-Tageswerte für 01.-04.11. (je 20 kWh:
     * 15 Heizen, 5 Warmwasser) und Thermik nur für den 03.11. (60 Heizen,
     * 15 Warmwasser). Ergibt Korrekturfaktor 88 / 80 = 1,1.
     */
    protected function seedMeterInterval(): void
    {
        $this->meterReading('2026-11-01 00:00:00', 1000, 500);
        $this->meterReading('2026-11-05 00:00:00', 1080, 508);

        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $values = [
                'power_heating_kwh' => 15,
                'power_dhw_kwh' => 5,
                'power_total_kwh' => 20,
            ];

            if ($date === '2026-11-03') {
                $values['thermal_heating_kwh'] = 60;
                $values['thermal_dhw_kwh'] = 15;
            }

            $this->apiLogFor($date, $values);
        }
    }
}
