<?php

namespace App\Enums;

/**
 * Grund einer Zaehlerstand-Erfassung (Release 2.0.0).
 *
 * - startsNewChain(): Die Ablesung beginnt eine neue Messreihe. Es gibt kein
 *   Intervall, das an ihr endet (Startwert bzw. neuer Zaehler mit eigenem
 *   Zaehlerstand - eine Differenz zum Vorgaenger waere sinnlos).
 * - isEstimate(): Der Wert ist geschaetzt; Intervalle, die ihn beruehren,
 *   gelten als nicht belastbar.
 */
enum MeterReadingReason: string
{
    case Regular = 'regular';
    case Final = 'final';
    case Annual = 'annual';
    case TariffChange = 'tariff_change';
    case MeterReplacement = 'meter_replacement';
    case StartValue = 'start_value';
    case Check = 'check';
    case Estimate = 'estimate';
    case Automatic = 'automatic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regelmessung',
            self::Final => 'Endablesung',
            self::Annual => 'Jahresablesung',
            self::TariffChange => 'Tarifwechsel',
            self::MeterReplacement => 'Zählertausch (neuer Zähler)',
            self::StartValue => 'Inbetriebnahme / Startwert',
            self::Check => 'Kontrollablesung',
            self::Estimate => 'Schätzwert',
            self::Automatic => 'Automatisch (IR-Lesekopf)',
            self::Other => 'Sonstiges',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Regular, self::Automatic => 'gray',
            self::Final, self::Annual => 'info',
            self::TariffChange, self::MeterReplacement, self::StartValue => 'warning',
            self::Check => 'success',
            self::Estimate => 'danger',
            self::Other => 'gray',
        };
    }

    public function startsNewChain(): bool
    {
        return in_array($this, [self::StartValue, self::MeterReplacement], true);
    }

    public function isEstimate(): bool
    {
        return $this === self::Estimate;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    /**
     * Werte der Gruende, die eine neue Messreihe beginnen (fuer Query-Filter).
     *
     * @return array<int, string>
     */
    public static function chainStartingValues(): array
    {
        return collect(self::cases())->filter(fn (self $case) => $case->startsNewChain())->map->value->values()->all();
    }
}
