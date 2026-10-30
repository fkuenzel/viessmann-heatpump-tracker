<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-filament::section heading="Viessmann-Verbindung">
            @if ($this->isViessmannConnected())
                <p class="text-sm text-success-600">Verbunden.</p>
            @else
                <p class="text-sm text-danger-600">
                    Nicht verbunden. Bitte
                    <a href="{{ url('/viessmann/connect') }}" class="underline">hier autorisieren</a>.
                </p>
            @endif

            @if ($log = $this->getLastApiLog())
                <p class="mt-2 text-sm text-gray-500">
                    Letzter Pull ({{ $log->source }}): {{ $log->timestamp->format('d.m.Y H:i') }}
                    &mdash; Gesamtverbrauch heute/zuletzt: {{ $log->power_total_kwh }} kWh
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Wetter">
            @if ($weather = $this->getLastWeatherLog())
                <p class="text-sm text-gray-500">
                    {{ $weather->timestamp->format('d.m.Y H:i') }}
                    &mdash; {{ $weather->temperature }} &deg;C, {{ $weather->humidity }} % Luftfeuchtigkeit
                </p>
            @else
                <p class="text-sm text-gray-500">Noch keine Wetterdaten erfasst.</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
