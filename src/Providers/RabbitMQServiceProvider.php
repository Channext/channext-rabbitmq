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
    public function register()
    {
        $this->app->singleton(RabbitMQ::class, function () {
            return new RabbitMQ();
        });
    }

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/rabbitmq.php' => base_path('config/rabbitmq.php'),
        ]);

        $this->publishes([
            __DIR__ . '/../routes/topics.php' => base_path('routes/topics.php'),
        ]);

        $this->publishes([
            __DIR__ . '/../Models/Message.php' => base_path('app/Models/Message.php'),
        ]);

        if (file_exists(base_path('routes/topics.php'))) {
            require base_path('routes/topics.php');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RabbitMQCommand::class,
            ]);
        }
    }
}
