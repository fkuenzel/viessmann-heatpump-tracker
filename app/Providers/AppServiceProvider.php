<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pro Request (bzw. Livewire-Aufruf) ein gemeinsamer Aggregationsservice,
        // damit alle Widgets denselben im Speicher gehaltenen api_log-Index nutzen.
        $this->app->scoped(\App\Services\ApiLogAggregationService::class);
        $this->app->scoped(\App\Services\PeriodReportService::class);
        $this->app->scoped(\App\Services\TariffLookup::class);

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate-Limit für den MCP-Endpoint (routes/ai.php): pro IP, großzügig
        // genug für einen LLM-Client (Handshake + mehrere Tool-Aufrufe pro
        // Frage), eng genug, um Token-Raten praktisch auszuschließen.
        RateLimiter::for('mcp-write', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }
}
