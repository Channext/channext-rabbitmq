<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Illuminate\Contracts\Auth\Authenticatable;

class EventAuth
{
    public Authenticatable|null $user;

    public function __construct()
    {
        $this->user = null;
    }

    // set user
    public function setUser(Authenticatable $user) : void
    {
        $this->user = $user;
    }

    // login
    public function login(Authenticatable $user, bool $remember = false) : void
    {
        if ($remember) throw new \Exception('Not implemented');
    }

    // logout
    public function logout() : void
    {
        $this->user = null;
    }

    // get user
    public function user(): Authenticatable|null
    {
        return $this->user;
    }

    // id()
    public function id(): int
    {
        return $this->user->id;
    }

    //check
    public function check(): bool
    {
        return (bool) $this->user;
    }

    // guest
    public function guest(): bool
    {
        return !$this->check();
    }


}
