<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\GetAnnotations;
use App\Models\Annotation;
use Illuminate\Support\Carbon;

class GetAnnotationsTest extends McpTestCase
{
    private function note(string $timestamp, string $note, string $category = 'beobachtung'): Annotation
    {
        return Annotation::create([
            'timestamp' => Carbon::parse($timestamp, 'Europe/Berlin'),
            'note' => $note,
            'category' => $category,
        ]);
    }

    public function test_returns_notes_in_range_newest_first(): void
    {
        $this->note('2026-10-01 10:00:00', 'zu alt');
        $this->note('2026-11-02 08:00:00', 'Fenster getauscht', 'wartung');
        $this->note('2026-11-05 18:30:00', 'Heizkurve geändert: Neigung von 1,2 auf 1 (automatisch erfasst)', 'einstellung');

        $data = $this->callTool(GetAnnotations::class);

        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['returned']);
        $this->assertFalse($data['truncated']);
        $this->assertSame('2026-11-05', $data['annotations'][0]['date']);
        $this->assertTrue($data['annotations'][0]['automatic']);
        $this->assertSame('Einstellung', $data['annotations'][0]['category_label']);
        $this->assertSame('Fenster getauscht', $data['annotations'][1]['note']);
        $this->assertFalse($data['annotations'][1]['automatic']);
    }

    public function test_filters_by_date_range_including_the_last_day(): void
    {
        $this->note('2026-11-03 23:50:00', 'am letzten Tag');
        $this->note('2026-11-04 00:10:00', 'danach');

        $data = $this->callTool(GetAnnotations::class, ['from' => '2026-11-03', 'to' => '2026-11-03']);

        $this->assertSame(1, $data['total']);
        $this->assertSame('am letzten Tag', $data['annotations'][0]['note']);
    }

    public function test_filters_by_category(): void
    {
        $this->note('2026-11-02 08:00:00', 'Wartung', 'wartung');
        $this->note('2026-11-03 08:00:00', 'Beobachtung', 'beobachtung');

        $data = $this->callTool(GetAnnotations::class, ['category' => 'wartung']);

        $this->assertSame(1, $data['total']);
        $this->assertSame('wartung', $data['annotations'][0]['category']);
    }

    public function test_manual_einstellung_note_is_not_marked_automatic(): void
    {
        $this->note('2026-11-02 08:00:00', 'Heizkurve von Hand angepasst', 'einstellung');

        $data = $this->callTool(GetAnnotations::class);

        $this->assertFalse($data['annotations'][0]['automatic']);
    }

    public function test_empty_database_returns_empty_list(): void
    {
        $data = $this->callTool(GetAnnotations::class);

        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['annotations']);
    }

    public function test_rejects_unknown_category_and_too_long_range(): void
    {
        $this->rawToolResponse(GetAnnotations::class, ['category' => 'unbekannt'])->assertHasErrors();
        $this->rawToolResponse(GetAnnotations::class, ['from' => '2025-01-01', 'to' => '2026-11-06'])->assertHasErrors();
    }
}
