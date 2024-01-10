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
    protected $signature = 'rabbitmq:listen
                            {--poll=10 : Polling frequency}
                            {--route-refresh=50 : Refresh routes if stale after this many polls}
                            {--timeout=60 : The number of seconds a child process can run}';

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
        $options = [
            'poll' => $this->option('poll'),
            'routeRefresh' => $this->option('route-refresh'),
            'timeout' => $this->option('timeout'),
        ];
        RabbitMQ::listenEvents($options);
    }
}
