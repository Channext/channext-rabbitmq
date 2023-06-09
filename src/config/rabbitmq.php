<?php

declare(strict_types=1);

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

    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => env('RABBITMQ_PORT', 15672),
    'user' => env('RABBITMQ_USER', 'local'),
    'password' => env('RABBITMQ_PASSWORD', 'rabbit'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'events_stream'),
    'queue' => env('RABBITMQ_QUEUE', 'notifications'),
];
