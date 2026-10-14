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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


'amadeus' => [
    'auth_url'          => env('AUTH_URL'),
    'flight_search'     => env('FLIGHT_SEARCH'),
    'confirm_price'     => env('CONFIRM_PRICE'),
    'flight_book'       => env('FLIGHT_BOOK'),
    'extra_bag'         => env('Extra_Bag'),
    'carrier_search'    => env('CARRIER_SEARCH'),
    'flight_details'    => env('FLIGHT_DETAILS'),
    'upselling'         => env(
        'FLIGHT_UPSELLING',
        'https://test.travel.api.amadeus.com/v1/shopping/flight-offers/upselling'
    ),
    'queuing_office_id' => env('AM_QUEUING_OFFICEID'),
    'booked_recipients' => env('BOOKED_RECIPIENT', '[]'),
    'client_id'         => env('CLIENT_ID'),
    'client_secret'     => env('CLIENT_SECRET'),
    'ca_bundle'         => storage_path('cacert.pem'),
    'search_cache_ttl'  => (int) env('FLIGHT_SEARCH_CACHE_TTL', 180),
    'confirmed_flight_ttl' => (int) env('FLIGHT_CONFIRMED_TTL', 7600),
    
    
],
'paystack' => [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
],

];
