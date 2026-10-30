<?php

return [
    'client_id' => env('VIESSMANN_CLIENT_ID'),
    'redirect_uri' => env('VIESSMANN_REDIRECT_URI', env('APP_URL') . '/viessmann/callback'),
    'scope' => env('VIESSMANN_SCOPE', 'IoT User offline_access'),

    'installation_id' => env('VIESSMANN_INSTALLATION_ID'),
    'gateway_serial' => env('VIESSMANN_GATEWAY_SERIAL'),
    'device_id' => env('VIESSMANN_DEVICE_ID', '0'),

    'auth_base' => env('VIESSMANN_AUTH_BASE', 'https://iam.viessmann-climatesolutions.com/idp/v2'),
    'api_base' => env('VIESSMANN_API_BASE', 'https://api.viessmann-climatesolutions.com/iot/v2'),
];
