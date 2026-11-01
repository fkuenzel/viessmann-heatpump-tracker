<x-filament-panels::page>
    <style>
        .device-status-sections {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .device-status-sections {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .device-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        .device-status-card {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            justify-content: space-between;
        }

        :where(.dark) .device-status-card {
            border-color: rgba(255, 255, 255, 0.1);
        }

        .device-status-label {
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .device-status-description {
            font-size: 0.6875rem;
            opacity: 0.6;
        }

        .device-status-value {
            font-size: 1.375rem;
            font-weight: 700;
        }

        .device-status-updated {
            font-size: 0.75rem;
            opacity: 0.6;
            margin-bottom: 0.75rem;
        }
    </style>

    @if (! $this->connected)
        <x-filament::section heading="Viessmann-Verbindung">
            <p class="text-sm text-danger-600">
                Nicht verbunden. Bitte
                <a href="{{ url('/viessmann/connect') }}" class="underline">hier autorisieren</a>.
            </p>
        </x-filament::section>
    @elseif ($this->error)
        <x-filament::section heading="Fehler beim Abruf">
            <p class="text-sm text-danger-600">{{ $this->error }}</p>
        </x-filament::section>
    @else
        @if ($this->lastUpdated)
            <div class="device-status-updated">
                Zuletzt aktualisiert: {{ $this->lastUpdated->format('d.m.Y H:i:s') }} Uhr
            </div>
        @endif

        <div class="device-status-sections">
            <x-filament::section heading="Wärmeerzeuger">
                <div class="device-status-grid">
                    @foreach ($this->getWaermeerzeugerRows() as $row)
                        <div class="device-status-card">
                            <div>
                                <div class="device-status-label">{{ $row['label'] }}</div>
                                <div class="device-status-description">{{ $row['description'] }}</div>
                            </div>
                            @if ($row['badge'])
                                <div><x-filament::badge :color="$row['badge']" size="lg">{{ $row['value'] }}</x-filament::badge></div>
                            @else
                                <div class="device-status-value">{{ $row['value'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Sekundärkreis">
                <div class="device-status-grid">
                    @foreach ($this->getSekundaerkreisRows() as $row)
                        <div class="device-status-card">
                            <div>
                                <div class="device-status-label">{{ $row['label'] }}</div>
                                <div class="device-status-description">{{ $row['description'] }}</div>
                            </div>
                            @if ($row['badge'])
                                <div><x-filament::badge :color="$row['badge']" size="lg">{{ $row['value'] }}</x-filament::badge></div>
                            @else
                                <div class="device-status-value">{{ $row['value'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Verdichter">
                <div class="device-status-grid">
                    @foreach ($this->getVerdichterRows() as $row)
                        <div class="device-status-card">
                            <div>
                                <div class="device-status-label">{{ $row['label'] }}</div>
                                <div class="device-status-description">{{ $row['description'] }}</div>
                            </div>
                            @if ($row['badge'])
                                <div><x-filament::badge :color="$row['badge']" size="lg">{{ $row['value'] }}</x-filament::badge></div>
                            @else
                                <div class="device-status-value">{{ $row['value'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
