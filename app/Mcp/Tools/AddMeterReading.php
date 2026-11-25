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
use App\Enums\MeterReadingReason;
use App\Models\MeterReading;
use App\Services\MeterReadingPlausibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

#[Name('add_meter_reading')]
#[Title('Zählerstand erfassen')]
#[Description('Erfasst einen Zählerstand (HT/NT in kWh, Zählerwerte sind kumulativ). Es gelten dieselben Prüfungen wie in der Oberfläche: Stände dürfen gegenüber der vorherigen und nächsten Ablesung nicht sinken (außer bei Zählertausch oder Startwert). Die Antwort enthält Plausibilitätshinweise, z. B. zum Korrekturfaktor. Ändert nichts an der Wärmepumpe.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AddMeterReading extends Tool
{
    use AuditsWrites;
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'counter_ht' => $schema->number()->description('Zählerstand HT in kWh.')->required(),
            'counter_nt' => $schema->number()->description('Zählerstand NT in kWh.')->required(),
            'timestamp' => $schema->string()->description('Zeitpunkt der Ablesung (JJJJ-MM-TT HH:MM, Europe/Berlin). Standard: jetzt.'),
            'reason' => $schema->string()->enum(array_column(MeterReadingReason::cases(), 'value'))->description('Grund der Ablesung. Standard: regular. "meter_replacement" und "start_value" beginnen eine neue Messreihe, "estimate" markiert einen Schätzwert, "other" erfordert eine Notiz.'),
            'note' => $schema->string()->description('Optionale Notiz (bei reason=other Pflicht).'),
            'meter_number' => $schema->string()->description('Zählernummer. Standard: Einstellung "Aktuelle Zählernummer".'),
        ];
    }

    public function handle(Request $request, MeterReadingPlausibilityService $plausibility): Response|ResponseFactory
    {
        $data = $request->validate([
            'counter_ht' => ['required', 'numeric', 'min:0'],
            'counter_nt' => ['required', 'numeric', 'min:0'],
            'timestamp' => ['nullable', 'date_format:Y-m-d H:i', 'before_or_equal:now'],
            'reason' => ['nullable', Rule::enum(MeterReadingReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'meter_number' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = MeterReadingReason::from($data['reason'] ?? 'regular');

        if ($reason === MeterReadingReason::Other && blank($data['note'] ?? null)) {
            return Response::error('Bei reason=other ist eine Notiz erforderlich.');
        }

        $timestamp = isset($data['timestamp']) ? Carbon::createFromFormat('Y-m-d H:i', $data['timestamp']) : now();
        $errors = $plausibility->errors($timestamp, (float) $data['counter_ht'], (float) $data['counter_nt'], $reason);

        if ($errors !== []) {
            return Response::error('Zählerstand nicht gespeichert: ' . implode(' ', $errors));
        }

        $reading = MeterReading::create([
            'timestamp' => $timestamp,
            'meter_number' => $data['meter_number'] ?? \App\Models\Setting::get('meter_number'),
            'counter_ht' => $data['counter_ht'],
            'counter_nt' => $data['counter_nt'],
            'reason' => $reason,
            'source' => 'mcp',
            'note' => $data['note'] ?? null,
        ]);

        $this->audit('add_meter_reading', $data, null, $reading->only(['id', 'timestamp', 'counter_ht', 'counter_nt', 'reason', 'source', 'note']));

        return Response::structured([
            'saved' => true,
            'id' => $reading->id,
            'timestamp' => $this->iso($reading->timestamp),
            'warnings' => $plausibility->warnings($reading),
        ]);
    }
}
