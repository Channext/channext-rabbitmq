<?php

namespace Channext\ChannextRabbitmq\Console\Commands;

use Illuminate\Console\Command;
use Channext\ChannextRabbitmq\Facades\RabbitMQ;

class RabbitMQListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:listen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen RabbitMQ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        RabbitMQ::listenEvents();
    }
}
