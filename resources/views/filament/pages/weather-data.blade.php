<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @livewire(\App\Filament\WeatherData\CurrentWeatherWidget::class)
        @livewire(\App\Filament\WeatherData\ForecastWeatherWidget::class)
        @livewire(\App\Filament\WeatherData\TemperatureChartWidget::class)
        @livewire(\App\Filament\WeatherData\TemperatureTableWidget::class)
    </div>
</x-filament-panels::page>
