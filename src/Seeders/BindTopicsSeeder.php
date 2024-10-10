<?php

namespace Channext\ChannextRabbitmq\Seeders;

use Channext\ChannextRabbitmq\Facades\RabbitMQ;
use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

use function Sentry\captureException;

class BindTopicsSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        try {
            RabbitMQ::unbindUnused();
        } catch (Exception $e) {
            captureException($e);
            Log::error('Failed to unbind unused topics: ' . $e->getMessage());
        }
    }
}