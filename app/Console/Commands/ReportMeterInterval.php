<?php

namespace App\Console\Commands;

use App\Models\MeterReading;
use App\Services\CopCalculationService;
use App\Services\CostCalculationService;
use App\Services\MeterCorrectionService;
use Illuminate\Console\Command;

class ReportMeterInterval extends Command
{
    protected $signature = 'report:meter-interval {fromId} {toId}';

    protected $description = 'Korrekturfaktor, COP und Kosten zwischen zwei Zaehlerstaenden berechnen (zum manuellen Pruefen von Phase 4)';

    public function handle(
        MeterCorrectionService $correction,
        CopCalculationService $cop,
        CostCalculationService $cost,
    ): int {
        $from = MeterReading::find($this->argument('fromId'));
        $to = MeterReading::find($this->argument('toId'));

        if (! $from || ! $to) {
            $this->error('Zaehlerstand nicht gefunden.');

            return self::FAILURE;
        }

        $this->info("Intervall: {$from->timestamp} -> {$to->timestamp}");
        $this->newLine();

        $this->info('--- Korrekturfaktor ---');
        $this->line(json_encode($correction->calculate($from, $to), JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('--- COP ---');
        $this->line(json_encode($cop->forRange($from->timestamp, $to->timestamp), JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('--- Kosten ---');
        $costResult = $cost->calculate($from, $to);
        $costResult['tariff'] = $costResult['tariff']?->only(['provider_name', 'tariff_name', 'ht_price', 'nt_price', 'base_fee', 'base_fee_period']);
        $this->line(json_encode($costResult, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
