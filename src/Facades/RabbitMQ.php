<?php

namespace Channext\ChannextRabbitmq\Facades;

use Illuminate\Support\Facades\Facade;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ as BaseRabbitMQ;

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