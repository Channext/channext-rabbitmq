<?php

namespace Channext\ChannextRabbitmq\Facades;

use Illuminate\Support\Facades\Facade;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ as BaseRabbitMQ;

/**
 * @method static void route(string $topic, string $callback, int $expiresIn = 0)
 **/
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return BaseRabbitMQ::class;
    }
}