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
use App\Services\ViessmannApiClient;
use App\Services\ViessmannSyncService;
use App\Services\WeatherSyncService;
use Illuminate\Support\Facades\Cache;

#[Name('trigger_sync')]
#[Title('Sync auslösen')]
#[Description('Löst einen Datenabruf aus (Viessmann-API und Wetter), wie der Button "Jetzt synchronisieren". Es werden nur Daten gelesen und gespeichert, die Wärmepumpe wird nicht gesteuert. Frühestens alle 5 Minuten, um das API-Kontingent zu schonen.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class TriggerSync extends Tool
{
    use AuditsWrites;

    public const COOLDOWN_SECONDS = 300;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, ViessmannSyncService $viessmann, WeatherSyncService $weather, ViessmannApiClient $client): Response|ResponseFactory
    {
        if (! $client->isConnected()) {
            return Response::error('Die Viessmann-Verbindung fehlt. Sie muss einmalig in der Anwendung autorisiert werden.');
        }

        $last = Cache::get('mcp_last_sync');

        if ($last !== null && now()->timestamp - (int) $last < self::COOLDOWN_SECONDS) {
            $wait = self::COOLDOWN_SECONDS - (now()->timestamp - (int) $last);

            return Response::error("Der letzte Sync war vor weniger als 5 Minuten. Bitte in {$wait} Sekunden erneut versuchen.");
        }

        Cache::forever('mcp_last_sync', now()->timestamp);

        try {
            $written = $viessmann->sync('manual');
            $weather->sync();
        } catch (\Throwable $e) {
            report($e);
            $this->audit('trigger_sync', [], null, ['error' => class_basename($e)]);

            return Response::error('Der Sync ist fehlgeschlagen (Details im Anwendungs-Log).');
        }

        $this->audit('trigger_sync', [], null, ['rows_written' => $written]);

        return Response::structured(['synced' => true, 'rows_written' => $written]);
    }
}
