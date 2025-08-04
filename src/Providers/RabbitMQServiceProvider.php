<?php

namespace Channext\ChannextRabbitmq\Providers;

use Channext\ChannextRabbitmq\Console\Commands\RabbitMQCommand;
use Channext\ChannextRabbitmq\Console\Commands\RabbitMQListenCommand;
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
        $this->app->bind(RabbitMQ::class, function () {
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
        if (!file_exists(base_path('config/rabbitmq.php'))) {
            $this->publishes([
                __DIR__ . '/../config/rabbitmq.php' => base_path('config/rabbitmq.php'),
            ]);
        }


        if (!file_exists(base_path('routes/topics.php'))) {
            $this->publishes([
                __DIR__ . '/../routes/topics.php' => base_path('routes/topics.php'),
            ]);
        }

        if (!file_exists(app('path') . '/Providers/EventAuthServiceProvider.php')) {
            $this->publishes([
                __DIR__ . '/EventAuthServiceProvider.php' => app('path') . '/Providers/EventAuthServiceProvider.php',
            ]);
        }

        if (!file_exists(app('path') . '/Providers/EventFailServiceProvider.php')) {
            $this->publishes([
                __DIR__ . '/EventFailServiceProvider.php' => app('path') . '/Providers/EventFailServiceProvider.php',
            ]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RabbitMQCommand::class,
                RabbitMQListenCommand::class
            ]);
        }
    }
}
