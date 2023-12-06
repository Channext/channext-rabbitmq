<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Illuminate\Foundation\Auth\User as AuthenticatableUser;

class EventAuth
{
    public AuthenticatableUser|null $user;

    public function __construct()
    {
        $this->user = null;
    }

    // set user
    public function setUser(AuthenticatableUser $user) : void
    {
        $this->user = $user;
    }

    // login
    public function login(AuthenticatableUser $user, bool $remember = false) : void
    {
        if ($remember) throw new \Exception('Not implemented');
    }

    // logout
    public function logout() : void
    {
        $this->user = null;
    }

    // get user
    public function user(): AuthenticatableUser|null
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
