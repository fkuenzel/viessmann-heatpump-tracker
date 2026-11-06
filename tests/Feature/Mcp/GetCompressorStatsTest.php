<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetCompressorStats;

class GetCompressorStatsTest extends McpTestCase
{
    private function seedCounters(): void
    {
        $this->apiLogFor('2026-11-02', ['compressor_starts' => 90, 'compressor_hours' => 45.0]);
        $this->apiLogFor('2026-11-03', ['compressor_starts' => 100, 'compressor_hours' => 50.0]);
        $this->apiLogFor('2026-11-04', ['compressor_starts' => 112, 'compressor_hours' => 56.5]);
        // 05.11. fehlt (Sync-Lücke), 06.11. ist heute und unvollständig
        $this->apiLogFor('2026-11-06', ['compressor_starts' => 125, 'compressor_hours' => 61.0], time: '11:30');
    }

    public function test_calculates_daily_deltas_and_totals_over_complete_days(): void
    {
        $this->seedCounters();

        $data = $this->callTool(GetCompressorStats::class, ['from' => '2026-11-03', 'to' => '2026-11-04']);
        $byDate = collect($data['daily'])->keyBy('date');

        $this->assertSame(10, $byDate['2026-11-03']['starts']);
        $this->assertEquals(5.0, $byDate['2026-11-03']['hours']);
        $this->assertEquals(0.5, $byDate['2026-11-03']['avg_hours_per_start']);
        $this->assertSame(12, $byDate['2026-11-04']['starts']);
        $this->assertEquals(6.5, $byDate['2026-11-04']['hours']);

        $this->assertSame(2, $data['totals']['complete_days_with_data']);
        $this->assertSame(22, $data['totals']['starts']);
        $this->assertEquals(11.5, $data['totals']['hours']);
        $this->assertEquals(11.0, $data['totals']['avg_starts_per_day']);
        $this->assertEquals(0.52, $data['totals']['avg_hours_per_start']);
    }

    public function test_gap_leaves_null_instead_of_a_wrong_delta(): void
    {
        $this->seedCounters();

        $data = $this->callTool(GetCompressorStats::class, ['from' => '2026-11-04', 'to' => '2026-11-06']);
        $byDate = collect($data['daily'])->keyBy('date');

        // 05.11. hat keinen Zählerstand => kein Eintrag, 06.11. hat keinen Vortagswert => null
        $this->assertFalse($byDate->has('2026-11-05'));
        $this->assertNull($byDate['2026-11-06']['starts']);
        $this->assertFalse($byDate['2026-11-06']['is_complete']);
        $this->assertSame(125, $byDate['2026-11-06']['total_starts_counter']);
    }

    public function test_todays_partial_day_is_excluded_from_totals(): void
    {
        $this->apiLogFor('2026-11-05', ['compressor_starts' => 120, 'compressor_hours' => 60.0]);
        $this->apiLogFor('2026-11-06', ['compressor_starts' => 125, 'compressor_hours' => 61.0], time: '11:30');

        $data = $this->callTool(GetCompressorStats::class, ['from' => '2026-11-05', 'to' => '2026-11-06']);

        $this->assertSame(0, $data['totals']['complete_days_with_data']);
        $this->assertNull($data['totals']['starts']);
    }

    public function test_returns_empty_series_without_data(): void
    {
        $data = $this->callTool(GetCompressorStats::class);

        $this->assertSame([], $data['daily']);
        $this->assertNull($data['totals']['starts']);
        $this->assertSame('2026-10-24', $data['from']);
        $this->assertSame('2026-11-06', $data['to']);
    }

    public function test_validates_the_date_range(): void
    {
        $this->rawToolResponse(GetCompressorStats::class, ['from' => '2026-11-05', 'to' => '2026-11-01'])->assertHasErrors();
        $this->rawToolResponse(GetCompressorStats::class, ['from' => '2026-01-01', 'to' => '2026-11-01'])->assertHasErrors();
        $this->rawToolResponse(GetCompressorStats::class, ['to' => '2026-11-07'])->assertHasErrors();
        $this->rawToolResponse(GetCompressorStats::class, ['from' => 'gestern'])->assertHasErrors();
    }
}
