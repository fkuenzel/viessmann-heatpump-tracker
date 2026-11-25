<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\HeatpumpServer;
use App\Mcp\Servers\HeatpumpWriteServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP-Server (Version 1.1.0)
|--------------------------------------------------------------------------
|
| Rein lesender Zugriff auf die Anlagendaten für MCP-Clients wie Claude.
| Transport: HTTP (POST /mcp), Absicherung über einen statischen
| Bearer-Token (MCP_API_TOKEN), siehe AuthenticateMcpToken. Das Rate-Limit
| "mcp" ist in AppServiceProvider definiert. Die Datei wird vom
| laravel/mcp-Paket automatisch geladen, ein Eintrag in bootstrap/app.php
| ist nicht nötig.
|
*/

Mcp::web('/mcp', HeatpumpServer::class)
    ->middleware(['throttle:mcp', AuthenticateMcpToken::class])
    ->name('mcp.heatpump');

// Schreibender Endpoint: eigener Token, eigenes (strengeres) Rate-Limit,
// jede Aenderung wird in mcp_audit_log protokolliert. Es gibt keine Tools,
// die die Waermepumpe steuern oder Zugangsdaten lesen/schreiben.
Mcp::web('/mcp/write', HeatpumpWriteServer::class)
    ->middleware(['throttle:mcp-write', AuthenticateMcpToken::class . ':write'])
    ->name('mcp.heatpump.write');
