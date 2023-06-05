<?php

namespace Channext\ChannextRabbitmq\Providers;

use Channext\ChannextRabbitmq\Console\Commands\RabbitMQCommand;
use Channext\ChannextRabbitmq\RabbitMQ\RabbitMQ;
use Illuminate\Support\ServiceProvider;

class RabbitMQServiceProvider extends ServiceProvider
{
    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->singleton(RabbitMQ::class, function () {
            return new RabbitMQ();
        });
        
        $this->mergeConfigFrom(__DIR__ . '/../config/services.php', 'services');
        $this->loadRoutesFrom(__DIR__ . '/../routes/topics.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RabbitMQCommand::class,
            ]);
        }
    }
}
