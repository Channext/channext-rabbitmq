<?php

declare(strict_types=1);

namespace App\Providers;

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
            // Todo: Implement your own logic here
            // it should return an Authenticatable instance of your user model

// Example:
//            return \App\Models\User::find($userData['id'] ?? null);


            return null;
        });

        $this->app['EventAuth']->setSerializeAuthUserCallback(function () {
            // Todo: Implement your own logic here
            // serialize the authenticated user so it can be used in the authUserCallback

// Example:
//            return Auth::check() ? ['id' => Auth::user()->id] ? null;

            return null;
        });
    }

}
