<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
| Which websites may call this API from the browser.
|
| Local development: leave FRONTEND_URL unset -> every origin is allowed.
| Deployed: set FRONTEND_URL to your frontend address(es), comma-separated,
|   e.g. FRONTEND_URL=https://csu-unischolar.vercel.app
| Login uses Bearer tokens (not cookies), so credentials are not needed.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', '*'))
)));

return [

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
