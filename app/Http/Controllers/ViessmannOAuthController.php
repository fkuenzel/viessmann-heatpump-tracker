<?php

namespace App\Http\Controllers;

use App\Services\ViessmannApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ViessmannOAuthController extends Controller
{
    public function __construct(private readonly ViessmannApiClient $client)
    {
    }

    public function connect(): RedirectResponse
    {
        return redirect()->away($this->client->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $this->client->handleCallback($request->string('code'), $request->string('state'));

        return redirect('/')->with('status', 'Viessmann-Verbindung erfolgreich autorisiert.');
    }
}
