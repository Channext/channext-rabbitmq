<?php

namespace Channext\ChannextRabbitmq\Facades;

use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQMessage;
use Illuminate\Support\Facades\Facade;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ as BaseRabbitMQ;

/**
 * @method static void route(string $route, string|array $callback, bool $retry = false)
 * @method static void universal(string $callback, bool $retry = false)
 * @method static void publish(array $body, string $routingKey, string|int $identifier = null, array $headers = [])
 * @method static void inject(string $queue, RabbitMQMessage $message)
 * @method static void function consume()
 * @method static null|RabbitMQMessage current()
 * @method static void consume()
 * @method static void listenEvents(array $options)
 * @method static void test(string $route, RabbitMQMessage $message)
 * @method static array getDefinedRoutes()
 * @method static array getBindings(?string $queue = null, ?string $exchange = null, ?string $vhost = '/')
 * @method static bool deleteBinding(string $route, ?string $queue = null, ?string $exchange = null, ?string $vhost = '/')
 * @method static bool unbindUnused()
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
