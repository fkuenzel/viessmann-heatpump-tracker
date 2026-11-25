<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'artisan_runner' => [
        // Ersatz fuer SSH/CLI auf Webspace ohne Shell-Zugriff, siehe
        // App\Http\Controllers\ArtisanRunnerController. Leer = Endpoint
        // deaktiviert (Standard im lokalen Betrieb).
        'token' => env('ARTISAN_RUNNER_TOKEN'),
    ],

    'mcp' => [
        // Bearer-Token für den MCP-Endpoint (POST /mcp, rein lesender Zugriff
        // auf die Anlagendaten), siehe App\Http\Middleware\AuthenticateMcpToken.
        // Leer oder kürzer als 32 Zeichen = Endpoint deaktiviert (404).
        // Bewusst getrennt vom artisan_runner-Token: ein kompromittierter
        // MCP-Zugriff soll keinen Zugriff auf den Scheduler-Ersatz geben.
        'token' => env('MCP_API_TOKEN'),

        // Eigener Bearer-Token fuer den schreibenden Endpoint (POST /mcp/write).
        // Leer, kuerzer als 32 Zeichen oder gleich dem Lese-Token = deaktiviert.
        'write_token' => env('MCP_WRITE_TOKEN'),
    ],

];
