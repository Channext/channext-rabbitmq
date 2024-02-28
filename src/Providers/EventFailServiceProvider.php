<?php

declare(strict_types=1);

namespace App\Providers;

use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ as RabbitMQBase;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQMessage;
use Illuminate\Support\ServiceProvider;

class EventFailServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('EventFail', function ($app) {
            return new RabbitMQBase();
        });
    }

    public function boot(): void {
        $this->app['EventFail']->setOnFailCallback(function (RabbitMQMessage $rabbitMessage, \Throwable $e, bool $retry) {
            // Todo: Implement your own logic here
            // This part should have your own event fail logic

// Example:
//            $routingKey = $rabbitMessage->getRoutingKey();
//            $retryAmount = $rabbitMessage->header('x-retry-state', 0);
//            RabbitMQ::publish([
//                'failReason' => get_class($e) . ' at ' . $e->getFile() . ' line ' . $e->getLine(),
//                'stackTrace' => $e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getTraceAsString(),
//                'traceId' => $rabbitMessage->getTraceId(),
//                'queue' => config('rabbitmq.queue'),
//                'eventFailed' => true,
//                'retryAmount' => $retryAmount,
//            ], "{$routingKey}.failed");
        });
    }

}
