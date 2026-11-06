<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Services\MeterIntervalBreakdownService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
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

/**
 * Entspricht dem Dashboard-Widget MeterIntervalBreakdownWidget (Tabelle
 * "Reale Aufteilung & COP (Zähler-Basis)"): beide nutzen denselben
 * MeterIntervalBreakdownService.
 */
#[Name('get_interval_breakdown')]
#[Title('Ablese-Intervalle: reale Aufteilung und COP')]
#[Description('Auswertung pro Ablese-Intervall (zwischen zwei Zählerständen): Zähler-Verbrauch (HT/NT) gegenüber App-Verbrauch samt Korrekturfaktor, Aufteilung Heizen/Warmwasser in Prozent und kWh, thermische Energie, echter COP auf Zähler-Basis (mit Zuverlässigkeitsflag) und Kosten, sofern ein Stromtarif hinterlegt ist. Entspricht der Dashboard-Tabelle "Reale Aufteilung & COP". Ohne Zeitraum werden die jüngsten Intervalle geliefert.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetIntervalBreakdown extends Tool
{
    use InteractsWithToolInput;

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')
                ->description('Nur Intervalle, deren Ende (Ablesezeitpunkt) am oder nach diesem Datum liegt (JJJJ-MM-TT).'),
            'to' => $schema->string()->format('date')
                ->description('Nur Intervalle, deren Ende (Ablesezeitpunkt) am oder vor diesem Datum liegt (JJJJ-MM-TT).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)
                ->description('Maximale Anzahl Intervalle, es werden die jüngsten geliefert (Standard ' . self::DEFAULT_LIMIT . ', maximal ' . self::MAX_LIMIT . ').'),
        ];
    }

    public function handle(Request $request, MeterIntervalBreakdownService $service): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $from = isset($validated['from']) ? Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'])->startOfDay() : null;
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return Response::error('Das Startdatum (from) muss vor oder gleich dem Enddatum (to) liegen.');
        }

        $endReadings = $service->intervalEndReadings($from, $to);
        $totalMatching = $endReadings->count();

        $intervals = $endReadings
            ->slice(-$limit)
            ->values()
            ->map(function ($reading) use ($service): array {
                $previous = $service->previousReading($reading);
                $b = $service->breakdown($reading);

                $meterKwh = $b['meter_kwh'];
                $apiKwh = $b['api_kwh'];

                return [
                    'period_from' => $this->iso($previous?->timestamp),
                    'period_to' => $this->iso($reading->timestamp),
                    'duration_days' => $b['duration_days'],
                    'avg_meter_kwh_per_day' => $b['avg_kwh_per_day'],
                    'consumption_kwh' => [
                        'meter_total' => $meterKwh,
                        'meter_ht' => $b['delta_ht_kwh'],
                        'meter_nt' => $b['delta_nt_kwh'],
                        'app_total' => $this->round($apiKwh),
                        'correction_factor' => $b['correction_factor'],
                        // Konvention der Excel-Tabelle / des Dashboards: (App - Zähler) / Zähler
                        'app_deviation_percent' => ($meterKwh !== null && $meterKwh > 0 && $apiKwh !== null)
                            ? $this->round((($apiKwh - $meterKwh) / $meterKwh) * 100)
                            : null,
                    ],
                    'split' => [
                        'heating_percent' => $b['heating_percent'],
                        'dhw_percent' => $b['dhw_percent'],
                        'heating_percent_cumulative' => $b['heating_percent_cumulative'],
                        'dhw_percent_cumulative' => $b['dhw_percent_cumulative'],
                        'real_heating_kwh' => $b['real_heating_kwh'],
                        'real_dhw_kwh' => $b['real_dhw_kwh'],
                        'app_heating_kwh' => $b['app_heating_kwh'],
                        'app_dhw_kwh' => $b['app_dhw_kwh'],
                    ],
                    'thermal_kwh' => $b['thermal_kwh'],
                    'cop' => [
                        'real' => $b['real_cop'],
                        'app' => $b['app_cop'],
                        'reliable' => $b['reliable'],
                        'note' => $b['reliable'] === false
                            ? 'Intervall kürzer als ' . MeterIntervalBreakdownService::MIN_RELIABLE_INTERVAL_DAYS . ' Tag: Thermik ist ein Tages-Snapshot, der COP ist nicht aussagekräftig.'
                            : null,
                    ],
                    'cost_eur' => $b['total_cost'] === null ? null : [
                        'total' => $b['total_cost'],
                        'heating' => $b['heating_cost'],
                        'dhw' => $b['dhw_cost'],
                        'tariff_changed_during_interval' => $b['tariff_changed_during_interval'],
                    ],
                ];
            })
            ->all();

        return Response::structured([
            'intervals' => $intervals,
            'returned' => count($intervals),
            'total_matching' => $totalMatching,
            'note' => $totalMatching === 0
                ? 'Keine Ablese-Intervalle gefunden (mindestens zwei Zählerstände nötig).'
                : null,
        ]);
    }
}
