<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AuditsWrites;
use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Support\SettingsWhitelist;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;

#[Name('update_setting')]
#[Title('Einstellung ändern')]
#[Description('Ändert eine Einstellung der Anwendung aus einer festen Positivliste (Abrechnungsjahr, Jahresplan, Abschlag, Anomalie-Schwellen, Report-Häufigkeit u. a., siehe get_settings). Zugangsdaten, Tokens, Mail-Empfänger und Wetter-Standort sind nicht änderbar. Ein leerer Wert setzt die Einstellung zurück, sofern sie optional ist.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(true)]
#[IsOpenWorld(false)]
class UpdateSetting extends Tool
{
    use AuditsWrites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->enum(SettingsWhitelist::keys())->description('Name der Einstellung.')->required(),
            'value' => $schema->string()->description('Neuer Wert als Text (Zahlen mit Punkt, Datum JJJJ-MM-TT). Leer = zurücksetzen.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'in:' . implode(',', SettingsWhitelist::keys())],
            'value' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $definition = SettingsWhitelist::all()[$input['key']];
        $value = ($input['value'] ?? '') === '' ? null : $input['value'];

        if ($value === null && ! in_array('nullable', $definition['rules'], true)) {
            return Response::error('Diese Einstellung ist nicht optional und kann nicht zurückgesetzt werden.');
        }

        $validator = Validator::make(['value' => $value], ['value' => $definition['rules']]);

        if ($validator->fails()) {
            return Response::error('Ungültiger Wert: ' . implode(' ', $validator->errors()->all()));
        }

        $before = Setting::get($input['key']);
        Setting::set($input['key'], $value);

        $this->audit('update_setting', $input, ['value' => $before], ['value' => $value]);

        return Response::structured(['saved' => true, 'key' => $input['key'], 'before' => $before, 'after' => $value]);
    }
}
