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

use App\Mcp\Support\SettingsWhitelist;
use App\Models\Setting;

#[Name('get_settings')]
#[Title('Einstellungen')]
#[Description('Aktuelle Werte der per MCP lesbaren und änderbaren Einstellungen (Positivliste). Zugangsdaten, Tokens und Mail-Empfänger werden nie ausgegeben.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetSettings extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $out = [];

        foreach (SettingsWhitelist::all() as $key => $def) {
            $out[] = ['key' => $key, 'label' => $def['label'], 'value' => Setting::get($key, $def['default'])];
        }

        return Response::structured(['settings' => $out]);
    }
}
