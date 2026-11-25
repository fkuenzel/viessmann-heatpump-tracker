<?php

namespace App\Mcp\Support;

use App\Models\McpAuditLog;

/**
 * Protokolliert jede schreibende MCP-Aktion (Werkzeug, Eingabe, Zustand
 * vorher und nachher). Zugangsdaten gelangen nie in die Eingabe, da keine
 * Tool-Schemas dafuer existieren; trotzdem werden Schluessel mit
 * token/secret/password/key im Protokoll maskiert.
 */
trait AuditsWrites
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function audit(string $tool, array $arguments, ?array $before, ?array $after): void
    {
        McpAuditLog::create([
            'tool' => $tool,
            'arguments' => $this->mask($arguments),
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/token|secret|password|key/i', (string) $key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }
}
