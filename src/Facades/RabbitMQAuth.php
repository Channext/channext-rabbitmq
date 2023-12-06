<?php

namespace Channext\ChannextRabbitmq\Facades;

use Channext\ChannextRabbitmq\RabbitMQ\EventAuth;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool check()
 * @method static bool guest()
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 * @method static int|string|null id()
 * @method static void setUser(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static void login(\Illuminate\Contracts\Auth\Authenticatable $user, bool $remember = false)
 * @method static void logout()
 **/
class RabbitMQAuth extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return EventAuth::class;
    }
}