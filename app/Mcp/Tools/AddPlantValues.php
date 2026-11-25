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
use App\Models\ApiLog;
use Illuminate\Support\Carbon;

#[Name('add_plant_values')]
#[Title('Anlagenwerte nachtragen')]
#[Description('Trägt die Momentaufnahme-Werte eines vergangenen Tages nach, die die Viessmann-API nicht rückwirkend liefert (Wärmemenge, Außentemperatur, Kompressor-Starts und -Laufzeit, Heizstab). Es wird eine Zeile mit source=backfill angelegt; für Tage mit bestehender Nachtrags-Zeile wird nichts überschrieben (dann Fehler). Strom-Tageswerte werden nicht nachgetragen. Ändert nichts an der Wärmepumpe.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AddPlantValues extends Tool
{
    use AuditsWrites;
    use InteractsWithToolInput;

    private const FIELDS = [
        'thermal_heating_kwh' => ['numeric', 'min:0', 'max:5000'],
        'thermal_dhw_kwh' => ['numeric', 'min:0', 'max:5000'],
        'outdoor_temp' => ['numeric', 'between:-50,60'],
        'compressor_starts' => ['integer', 'min:0'],
        'compressor_hours' => ['numeric', 'min:0'],
        'heating_rod_heating_kwh' => ['numeric', 'min:0', 'max:1000'],
        'heating_rod_dhw_kwh' => ['numeric', 'min:0', 'max:1000'],
        'heating_rod_starts' => ['integer', 'min:0'],
        'heating_rod_hours' => ['numeric', 'min:0'],
    ];

    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $fields[$field] = $schema->number()->description('Optional: ' . $field . ' (Tageswert bzw. Zählerstand zum Tagesende).');
        }

        return ['date' => $schema->string()->format('date')->description('Tag (JJJJ-MM-TT), höchstens gestern.')->required(), ...$fields];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before:today'],
            ...collect(self::FIELDS)->map(fn ($rules) => ['nullable', ...$rules])->all(),
        ]);

        $values = collect($data)->except('date')->filter(fn ($v) => $v !== null)->all();

        if ($values === []) {
            return Response::error('Mindestens ein Wert muss angegeben werden.');
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();

        $exists = ApiLog::query()
            ->where('source', 'backfill')
            ->where('day_index', 0)
            ->whereDate('timestamp', $date)
            ->exists();

        if ($exists) {
            return Response::error('Für diesen Tag existiert bereits ein Nachtrag. Bitte in der Oberfläche unter "Anlagenwerte" ändern.');
        }

        $row = ApiLog::create([...$values, 'timestamp' => $date->copy()->setTime(12, 0), 'source' => 'backfill', 'day_index' => 0]);

        $this->audit('add_plant_values', $data, null, $row->only(['id', 'timestamp', 'source', ...array_keys($values)]));

        return Response::structured(['saved' => true, 'id' => $row->id, 'date' => $date->toDateString(), 'fields' => array_keys($values)]);
    }
}
