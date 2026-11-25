<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\HeatpumpWriteServer;
use App\Mcp\Tools\AddAnnotation;
use App\Mcp\Tools\AddMeterReading;
use App\Mcp\Tools\AddPlantValues;
use App\Mcp\Tools\AddTariff;
use App\Mcp\Tools\GetAuditLog;
use App\Mcp\Tools\GetChangeImpact;
use App\Mcp\Tools\GetPeriodReport;
use App\Mcp\Tools\GetSettings;
use App\Mcp\Tools\TriggerSync;
use App\Mcp\Tools\UpdateSetting;
use App\Models\Annotation;
use App\Models\ApiLog;
use App\Models\McpAuditLog;
use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Models\Setting;
use Laravel\Mcp\Server\Testing\TestResponse;

class McpWriteTest extends McpTestCase
{
    private const READ = 'a3f1c9d2b7e84a6f9c0d5e1b2a7c8d4e6f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c';

    private const WRITE = 'f9e8d7c6b5a49382716051423324354657687980a1b2c3d4e5f60718293a4b5c';

    protected function rawToolResponse(string $tool, array $arguments = []): TestResponse
    {
        return HeatpumpWriteServer::tool($tool, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function init(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]];
    }

    private function postWrite(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/mcp/write', $this->init(), ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $token]);
    }

    public function test_write_endpoint_requires_its_own_token(): void
    {
        config(['services.mcp.token' => self::READ, 'services.mcp.write_token' => self::WRITE]);

        $this->postWrite(self::READ)->assertUnauthorized();
        $this->postWrite(self::WRITE)->assertOk();
    }

    public function test_write_token_does_not_open_the_read_endpoint(): void
    {
        config(['services.mcp.token' => self::READ, 'services.mcp.write_token' => self::WRITE]);

        $this->postJson('/mcp', $this->init(), ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . self::WRITE])->assertUnauthorized();
    }

    public function test_write_endpoint_is_disabled_without_token_or_with_identical_tokens(): void
    {
        config(['services.mcp.token' => self::READ, 'services.mcp.write_token' => null]);
        $this->postWrite(self::WRITE)->assertNotFound();

        config(['services.mcp.write_token' => self::READ]);
        $this->postWrite(self::READ)->assertNotFound();

        config(['services.mcp.write_token' => 'kurz']);
        $this->postWrite('kurz')->assertNotFound();
    }

    public function test_write_endpoint_is_rate_limited(): void
    {
        config(['services.mcp.token' => self::READ, 'services.mcp.write_token' => self::WRITE]);

        for ($i = 0; $i < 20; $i++) {
            $this->postWrite(self::WRITE)->assertOk();
        }

        $this->postWrite(self::WRITE)->assertStatus(429);
    }

    public function test_read_server_has_no_write_tools(): void
    {
        $names = collect(\App\Mcp\Servers\HeatpumpServer::READ_TOOLS)->map(fn ($c) => (new $c)->name());

        foreach (['add_meter_reading', 'add_annotation', 'add_tariff', 'update_setting', 'add_plant_values', 'trigger_sync'] as $write) {
            $this->assertNotContains($write, $names->all());
        }

        foreach (\App\Mcp\Servers\HeatpumpServer::READ_TOOLS as $class) {
            $this->assertTrue((new $class)->toArray()['annotations']['readOnlyHint'] ?? false, $class);
        }
    }

    public function test_add_meter_reading_saves_and_audits(): void
    {
        $data = $this->callTool(AddMeterReading::class, ['counter_ht' => 1000.5, 'counter_nt' => 200.25, 'timestamp' => '2026-11-05 08:00']);

        $this->assertTrue($data['saved']);
        $reading = MeterReading::find($data['id']);
        $this->assertSame('mcp', $reading->source);
        $this->assertSame('regular', $reading->reason->value);

        $log = McpAuditLog::first();
        $this->assertSame('add_meter_reading', $log->tool);
        $this->assertSame(1000.5, (float) $log->after['counter_ht']);
    }

    public function test_add_meter_reading_rejects_decreasing_counter(): void
    {
        $this->meterReading('2026-11-01 08:00', 1000, 200);

        $this->rawToolResponse(AddMeterReading::class, ['counter_ht' => 900, 'counter_nt' => 210, 'timestamp' => '2026-11-05 08:00'])
            ->assertHasErrors(['HT-Stand liegt unter']);

        $this->assertSame(1, MeterReading::count());
        $this->assertSame(0, McpAuditLog::count());
    }

    public function test_add_meter_reading_other_reason_needs_note(): void
    {
        $this->rawToolResponse(AddMeterReading::class, ['counter_ht' => 10, 'counter_nt' => 1, 'reason' => 'other'])->assertHasErrors(['Notiz']);
    }

    public function test_add_annotation(): void
    {
        $data = $this->callTool(AddAnnotation::class, ['note' => 'Heizkurve Steilheit 0,8', 'category' => 'einstellung']);

        $this->assertTrue($data['saved']);
        $this->assertSame('einstellung', Annotation::first()->category);
        $this->assertSame(1, McpAuditLog::where('tool', 'add_annotation')->count());
    }

    public function test_add_tariff_rejects_duplicate_valid_from(): void
    {
        $args = ['valid_from' => '2027-01-01', 'ht_price' => 0.2403, 'nt_price' => 0.2403, 'base_fee' => 36.41, 'provider_name' => 'Stadtwerke', 'tariff_name' => 'Wärmepumpenstrom'];

        $this->assertTrue($this->callTool(AddTariff::class, $args)['saved']);
        $this->assertSame('yearly', PriceHistory::first()->base_fee_period);

        $this->rawToolResponse(AddTariff::class, $args)->assertHasErrors();
        $this->assertSame(1, PriceHistory::count());
    }

    public function test_update_setting_validates_and_records_before_after(): void
    {
        Setting::set('plan_kwh', '4000');

        $data = $this->callTool(UpdateSetting::class, ['key' => 'plan_kwh', 'value' => '4500']);

        $this->assertSame('4000', $data['before']);
        $this->assertSame('4500', Setting::get('plan_kwh'));
        $this->assertSame('4000', McpAuditLog::first()->before['value']);

        $this->rawToolResponse(UpdateSetting::class, ['key' => 'forecast_horizon_days', 'value' => '99'])->assertHasErrors();
        $this->rawToolResponse(UpdateSetting::class, ['key' => 'forecast_horizon_days', 'value' => ''])->assertHasErrors(['nicht optional']);
    }

    public function test_update_setting_refuses_credentials_and_unlisted_keys(): void
    {
        foreach (['viessmann_access_token', 'mail_recipient', 'weather_location_name', 'APP_KEY'] as $key) {
            $this->rawToolResponse(UpdateSetting::class, ['key' => $key, 'value' => 'x'])->assertHasErrors();
            $this->assertNotSame('x', Setting::get($key));
        }

        $this->assertSame(0, McpAuditLog::count());
    }

    public function test_get_settings_never_exposes_credentials(): void
    {
        Setting::set('viessmann_access_token', 'geheim-token');
        Setting::set('plan_kwh', '4000');

        $json = json_encode($this->callTool(GetSettings::class));

        $this->assertStringContainsString('plan_kwh', $json);
        $this->assertStringNotContainsString('geheim-token', $json);
        $this->assertStringNotContainsString('viessmann_access_token', $json);
    }

    public function test_add_plant_values_creates_one_backfill_row_per_day(): void
    {
        $args = ['date' => '2026-11-03', 'thermal_heating_kwh' => 60, 'outdoor_temp' => 4.5];

        $this->assertTrue($this->callTool(AddPlantValues::class, $args)['saved']);

        $row = ApiLog::first();
        $this->assertSame('backfill', $row->source);
        $this->assertSame(0, $row->day_index);
        $this->assertNull($row->power_total_kwh);

        $this->rawToolResponse(AddPlantValues::class, $args)->assertHasErrors(['bereits']);
        $this->rawToolResponse(AddPlantValues::class, ['date' => '2026-11-04'])->assertHasErrors(['Mindestens']);
        $this->rawToolResponse(AddPlantValues::class, ['date' => '2026-11-06', 'outdoor_temp' => 3])->assertHasErrors();
        $this->assertSame(1, ApiLog::count());
    }

    public function test_trigger_sync_without_connection_fails_cleanly(): void
    {
        $this->rawToolResponse(TriggerSync::class)->assertHasErrors(['Verbindung']);
        $this->assertSame(0, McpAuditLog::count());
    }

    public function test_trigger_sync_respects_cooldown(): void
    {
        $client = $this->mock(\App\Services\ViessmannApiClient::class, fn ($m) => $m->shouldReceive('isConnected')->andReturn(true));
        $this->mock(\App\Services\ViessmannSyncService::class, fn ($m) => $m->shouldReceive('sync')->once()->with('manual')->andReturn(5));
        $this->mock(\App\Services\WeatherSyncService::class, fn ($m) => $m->shouldReceive('sync')->once());

        $this->assertSame(5, $this->callTool(TriggerSync::class)['rows_written']);
        $this->rawToolResponse(TriggerSync::class)->assertHasErrors(['5 Minuten']);
        $this->assertSame(1, McpAuditLog::count());
    }

    public function test_audit_log_masks_secret_like_keys_and_is_readable(): void
    {
        $tool = new class extends \Laravel\Mcp\Server\Tool
        {
            use \App\Mcp\Support\AuditsWrites;

            public function run(): void
            {
                $this->audit('x', ['api_token' => 'abc', 'nested' => ['password' => 'p'], 'ok' => 1], null, null);
            }
        };
        $tool->run();

        $args = McpAuditLog::first()->arguments;
        $this->assertSame('***', $args['api_token']);
        $this->assertSame('***', $args['nested']['password']);
        $this->assertSame(1, $args['ok']);

        $this->assertCount(1, $this->callTool(GetAuditLog::class)['entries']);
    }

    public function test_period_report_and_change_impact_tools(): void
    {
        $this->apiLogFor('2026-11-03', ['power_total_kwh' => 20, 'power_heating_kwh' => 15, 'power_dhw_kwh' => 5]);

        $report = $this->callTool(GetPeriodReport::class, ['preset' => 'last_7', 'compare' => 'previous']);
        $this->assertArrayHasKey('current', $report);
        $this->assertArrayHasKey('previous', $report);

        $this->assertEquals(20.0, $report['current']['app']['power_total_kwh']);

        $impact = $this->callTool(GetChangeImpact::class, ['at' => '2026-11-04 10:00', 'days' => 3]);
        $this->assertSame(2, $impact['after']['days']);
        $this->assertSame('2026-11-01', $impact['before']['from']);
    }
}
