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
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }
}
