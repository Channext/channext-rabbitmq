<?php

declare(strict_types=1);

namespace Channext\ChannextRabbitmq\Providers;

use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ;
use Illuminate\Support\ServiceProvider;

class EventAuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('EventAuth', function ($app) {
            return new RabbitMQ();
        });
    }

    public function boot(): void {
        $this->app['EventAuth']->setAuthUserCallback(function ($userData) {
            // put your auth user logic here:


            // todo: return $user;
        });
    }

}
