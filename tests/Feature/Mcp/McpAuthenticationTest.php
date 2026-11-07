<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a3f1c9d2b7e84a6f9c0d5e1b2a7c8d4e6f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c';

    /**
     * @return array<string, mixed>
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $token = self::TOKEN): array
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    public function test_endpoint_is_disabled_without_configured_token(): void
    {
        config(['services.mcp.token' => null]);

        $this->postJson('/mcp', $this->initializePayload(), $this->headers())->assertNotFound();
    }

    public function test_endpoint_is_disabled_when_token_is_too_short(): void
    {
        config(['services.mcp.token' => 'kurz']);

        $this->postJson('/mcp', $this->initializePayload(), $this->headers('kurz'))->assertNotFound();
    }

    public function test_request_without_token_is_rejected(): void
    {
        config(['services.mcp.token' => self::TOKEN]);

        $this->postJson('/mcp', $this->initializePayload(), $this->headers(null))->assertUnauthorized();
    }

    public function test_request_with_wrong_token_is_rejected(): void
    {
        config(['services.mcp.token' => self::TOKEN]);

        $this->postJson('/mcp', $this->initializePayload(), $this->headers(strrev(self::TOKEN)))->assertUnauthorized();
    }

    public function test_token_in_query_string_is_not_accepted(): void
    {
        config(['services.mcp.token' => self::TOKEN]);

        $this->postJson('/mcp?token=' . self::TOKEN, $this->initializePayload(), $this->headers(null))->assertUnauthorized();
    }

    public function test_valid_token_reaches_the_server(): void
    {
        config(['services.mcp.token' => self::TOKEN]);

        $response = $this->postJson('/mcp', $this->initializePayload(), $this->headers());

        $response->assertOk();
        $response->assertJsonPath('result.serverInfo.name', 'Viessmann Heatpump Tracker');
        $response->assertJsonPath('result.serverInfo.version', '1.2.1');
    }

    public function test_plain_http_is_rejected_in_production(): void
    {
        config(['services.mcp.token' => self::TOKEN]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->postJson('http://localhost/mcp', $this->initializePayload(), $this->headers())->assertForbidden();
    }

    public function test_https_is_accepted_in_production(): void
    {
        config(['services.mcp.token' => self::TOKEN]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->postJson('https://localhost/mcp', $this->initializePayload(), $this->headers())->assertOk();
    }

    public function test_requests_are_rate_limited(): void
    {
        config(['services.mcp.token' => self::TOKEN]);

        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/mcp', $this->initializePayload(), $this->headers(null))->assertUnauthorized();
        }

        $this->postJson('/mcp', $this->initializePayload(), $this->headers())->assertStatus(429);
    }
}
