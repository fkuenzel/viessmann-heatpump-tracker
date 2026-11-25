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

use App\Models\McpAuditLog;

#[Name('get_audit_log')]
#[Title('Änderungsprotokoll')]
#[Description('Protokoll der per MCP durchgeführten Schreibaktionen (Werkzeug, Eingabe, Zustand vorher und nachher), neueste zuerst.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetAuditLog extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Anzahl (1 bis 100). Standard: 20.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'between:1,100']])['limit'] ?? 20);

        return Response::structured(['entries' => McpAuditLog::query()->orderByDesc('id')->limit($limit)->get()->map(fn ($e) => [
            'id' => $e->id, 'at' => $e->created_at?->toIso8601String(), 'tool' => $e->tool,
            'arguments' => $e->arguments, 'before' => $e->before, 'after' => $e->after,
        ])->all()]);
    }
}
