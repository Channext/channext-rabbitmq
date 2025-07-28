<?php

namespace Channext\ChannextRabbitmq\Console\Commands;

use Illuminate\Console\Command;
use Channext\ChannextRabbitmq\Facades\RabbitMQ;

class RabbitMQCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume
                            {--once : Process a single message and exit}
                            {--info : Display message info}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume RabbitMQ';

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
        $once = $this->option('once');
        $info = $this->option('info');

        if (env("RABBITMQ_CONSUME_DISABLED", false)) {
            RabbitMQ::keepAlive();
        } else {
            RabbitMQ::consume($once, $info ? $this : null);
        }
    }
}
