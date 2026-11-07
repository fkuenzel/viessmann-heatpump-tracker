<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\HeatpumpServer;
use App\Mcp\Tools\CheckAnomalies;
use App\Mcp\Tools\GetAnnotations;
use App\Mcp\Tools\GetCompressorStats;
use App\Mcp\Tools\GetCopTrend;
use App\Mcp\Tools\GetCurrentStatus;
use App\Mcp\Tools\GetDailySummary;
use App\Mcp\Tools\GetIntervalBreakdown;
use App\Mcp\Tools\GetWeatherComparison;

class HeatpumpServerTest extends McpTestCase
{
    /**
     * @return array<string, class-string>
     */
    private function expectedTools(): array
    {
        return [
            'get_current_status' => GetCurrentStatus::class,
            'get_daily_summary' => GetDailySummary::class,
            'get_interval_breakdown' => GetIntervalBreakdown::class,
            'get_cop_trend' => GetCopTrend::class,
            'check_anomalies' => CheckAnomalies::class,
            'get_compressor_stats' => GetCompressorStats::class,
            'get_weather_comparison' => GetWeatherComparison::class,
            'get_annotations' => GetAnnotations::class,
        ];
    }

    public function test_all_planned_tools_are_registered(): void
    {
        HeatpumpServer::tools()->assertRegistered(array_values($this->expectedTools()));
    }

    public function test_tools_carry_the_planned_names(): void
    {
        foreach ($this->expectedTools() as $name => $class) {
            $this->rawToolResponse($class)->assertName($name);
        }
    }

    public function test_every_tool_is_marked_read_only(): void
    {
        foreach ($this->expectedTools() as $class) {
            $annotations = (new $class)->toArray()['annotations'];

            $this->assertTrue($annotations['readOnlyHint'] ?? false, "{$class} ist nicht als readOnly markiert.");
            $this->assertFalse($annotations['openWorldHint'] ?? true, "{$class} ist nicht als geschlossen markiert.");
        }
    }

    public function test_every_tool_runs_on_an_empty_database_without_errors(): void
    {
        foreach ($this->expectedTools() as $class) {
            if ($class === GetWeatherComparison::class) {
                // Ohne Wetter-Standort liefert dieses Tool bewusst eine
                // verständliche Fehlermeldung, siehe GetWeatherComparisonTest.
                continue;
            }

            $this->rawToolResponse($class)->assertHasNoErrors();
        }
    }
}
