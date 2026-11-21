<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Online payments (see config/payments.php). The webhook secret may list
    // several comma-separated secrets: the platform and the Connect endpoints
    // each have their own.
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Converts "Hoje tem!" videos. Names on the PATH or absolute paths.
    'ffmpeg' => [
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],

    // Restaurants' addresses on the map, for "closest first" on the discovery page
    // (App\Support\Geo\Geocoder, on the queue). A Nominatim-compatible search URL; empty turns it off.
    'geocoding' => [
        'url' => env('GEOCODING_URL', 'https://nominatim.openstreetmap.org/search'),
        'country' => env('GEOCODING_COUNTRY', 'br'),
    ],

    // Where a visitor roughly is when the browser doesn't share its location
    // (App\Support\Geo\IpLocator). "{ip}" is replaced; the JSON answer has latitude and longitude.
    // Off by default: it sends the visitor's IP address to a service abroad (LGPD Art. 33).
    // Cloudflare's visitor location headers are used first when present, with no outside call.
    // Turning it on (e.g. https://ipwho.is/{ip}) must be listed in the privacy policy.
    'ip_location' => [
        'url' => env('IP_LOCATION_URL', ''),
    ],

];
