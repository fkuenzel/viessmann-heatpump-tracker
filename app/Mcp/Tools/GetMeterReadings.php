<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use App\Models\MeterReading;

#[Name('get_meter_readings')]
#[Title('Zählerstände')]
#[Description('Erfasste Zählerstände (HT/NT, Grund, Quelle, Notiz), neueste zuerst. Standard: die letzten 20.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetMeterReadings extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Anzahl (1 bis 100). Standard: 20.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'between:1,100']])['limit'] ?? 20);

        $rows = MeterReading::query()->orderByDesc('timestamp')->limit($limit)->get()->map(fn (MeterReading $r): array => [
            'id' => $r->id,
            'timestamp' => $this->iso($r->timestamp),
            'counter_ht' => (float) $r->counter_ht,
            'counter_nt' => (float) $r->counter_nt,
            'reason' => $r->reason->value,
            'reason_label' => $r->reason->label(),
            'source' => $r->source,
            'note' => $r->note,
        ])->all();

        return Response::structured(['total' => MeterReading::count(), 'returned' => count($rows), 'readings' => $rows]);
    }
}
