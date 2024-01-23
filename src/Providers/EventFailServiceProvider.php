<?php

declare(strict_types=1);

namespace Channext\ChannextRabbitmq\Providers;

use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQMessage;
use Illuminate\Support\ServiceProvider;

class EventFailServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('EventFail', function ($app) {
            return new RabbitMQ();
        });
    }

    public function boot(): void {
        $this->app['EventAuth']->setAuthUserCallback(function (RabbitMQMessage $rabbitMessage, \Throwable $e, bool $retry) {
            // Todo: Implement your own logic here
            // This part should have your own event fail logic

// Example:
//            $routingKey = $rabbitMessage->getRoutingKey();
//        if ($retry) {
//            self::publish([
//                'failReason' => get_class($e) . " at " . $e->getFile() . " line " . $e->getLine(),
//                'stackTrace' => $e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getTraceAsString(),
//                'traceId' => $rabbitMessage->getTraceId(),
//                'queue' => config('rabbitmq.queue'),
//            ], "$routingKey.failed");
//        }



        });
    }

}
