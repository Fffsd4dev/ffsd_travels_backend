<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Here you may configure your settings for cross-origin resource sharing
| or "CORS". This determines what cross-origin operations may execute
| in web browsers. You are free to adjust these settings as needed.
|
| To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
|
*/

return [
    'paths' => ['api/*'], // Apply CORS only on API routes

    'allowed_methods' => ['GET', 'POST', 'OPTIONS','PUT','DELETE'], // Restrict to necessary methods

    'allowed_origins' => ['*'], // Allow any origin (fixed issue here)

    'allowed_origins_patterns' => [], // Leave empty unless regex patterns for origins are required

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Origin', 'Accept'], // Allow these headers

    'exposed_headers' => ['Content-Type', 'X-Requested-With'], // Expose necessary headers

    'max_age' => 0, // Set to 0 (no caching for preflight)

    'supports_credentials' => false, // Set to false unless you need to allow credentials
];
