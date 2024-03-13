<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Channext\ChannextRabbitmq\Facades\RabbitMQAuth;
use Closure;
use ErrorException;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Process\Process;
use function Sentry\captureException;

class RabbitMQ
{
    /**
     * @var ?AMQPStreamConnection $connection
     */
    private ?AMQPStreamConnection $connection = null;

    /**
     * @var ?AMQPChannel $channel
     */
    private ?AMQPChannel $channel = null;

    /**
     * @var int $deliveryMode
     */
    private int $deliveryMode = AMQPMessage::DELIVERY_MODE_PERSISTENT;

    /**
     * @var array $routes
     */
    private array $routes = [];
    private Closure|null $authUserCallback;
    private Closure|null $serializeAuthUserCallback;
    private Closure|null $onFailCallback;
    private static ?RabbitMQMessage $currentMessage = null;


    /**
     * RabbitMQ constructor.
     * @throws Exception
     */
    public function __construct()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                host: config('rabbitmq.host'),
                port: config('rabbitmq.port'),
                user: config('rabbitmq.user'),
                password: config('rabbitmq.password')
            );
            $this->channel = $this->connection?->channel();
            $this->channel?->exchange_declare(
                exchange: config('rabbitmq.exchange'),
                type: 'topic',
                durable: true,
                auto_delete: false
            );
            $this->channel?->queue_declare(
                queue: config('rabbitmq.queue'),
                durable: true,
                auto_delete: false,
                arguments: ['x-max-priority' => array('I', 5)]
            );
            $this->authUserCallback = null;
            $this->serializeAuthUserCallback = null;
        } catch (\Throwable $e) {
            captureException($e);
        }
    }

    /**
     * EventsService destructor.
     */
    public function __destruct()
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable $e) {
            captureException($e);
        }
    }

    /**
     * Define routing key and bind it to a queue
     *
     * @param string $route
     * @param string|array $callback
     * @param bool $retry
     * @return void
     */
    public function route(string $route, string|array $callback, bool $retry = false) : void
    {
        if (array_key_exists('#', $this->routes)) {
            if (env("APP_ENV") === 'local') Log::warning("An universal route already exists. No specific routes can be added.");
            return;
        }
        if (!array_key_exists($route, $this->routes)) {
            $this->routes[$route] = [$this->createAction(callback: $callback), $retry];

            try {
                $this->channel?->queue_bind(
                    queue: config('rabbitmq.queue'),
                    exchange: config('rabbitmq.exchange'),
                    routing_key: $route
                );
            } catch (\Throwable $e) {
                captureException($e);
            }
        }
    }

    /**
     * Define a universal event listener
     *
     * @param string $callback
     * @param bool $retry
     * @return void
     */
    public function universal(string $callback, bool $retry = false) : void
    {
        $this->route(route: '#', callback: $callback, retry: $retry);
    }

    /**
     * Consume messages
     *
     * @param bool $once
     * @return void
     */
    public function consume($once = false) : void
    {
        try {
            if ($once) $this->listen();
            else $this->work();
        } catch (\Throwable $e) {
            $this->logLocalErrors($e);
            $this->flush();
            captureException($e);
        }
    }

    /**
     * Regular ever running consumer.
     *
     * @return void
     * @throws ErrorException
     */
    protected function work() : void
    {
        $this->channel?->basic_consume(queue: config('rabbitmq.queue'), callback: [$this, 'callback']);
        $this->channel?->consume();
    }

    /**
     * listens for messages, if found processes one and returns
     *
     * @return void
     */
    protected function listen() : void
    {
        $message = $this->channel?->basic_get(queue: config('rabbitmq.queue'));
        if ($message) $this->callback($message);
    }

    /**
     * Returns true if there is a message in the queue
     * @return bool
     */
    public function hasMessage() : bool
    {
        return (bool) $this->channel?->queue_declare(
            queue: config('rabbitmq.queue'),
            passive: true,
            durable: true,
            auto_delete: false
        )[1] ?? false;
    }

    /**
     * Purge the queue
     *
     * @return void
     */
    public function purge() : void
    {
        $this->channel?->queue_purge(queue: config('rabbitmq.queue'));
    }

    /**
     * Inject message to a specific queue
     *
     * @param string $queue
     * @param RabbitMQMessage $message
     * @return void
     */
    public function inject(string $queue, RabbitMQMessage $message): void
    {
        $this->channel?->queue_declare(
            queue: $queue,
            durable: true,
            auto_delete: false,
            arguments: ['x-max-priority' => array('I', 5)]
        );
        $this->channel?->basic_publish(
            msg: $message->getOriginalMessage(),
            routing_key: $queue // route directly to the queue
        );
    }

    /**
     * Publish message
     *
     * @param array $body
     * @param string $routingKey
     * @param string|int|null $identifier
     * @param array $headers
     * @return void
     */
    public function publish(array $body, string $routingKey, string|int $identifier = null, array $headers = []) : void
    {
        try {
            $headers['x-identifier'] = $identifier;
            $headers = $this->setUserData($headers);
            $message = RabbitMQMessage::make($routingKey, $body, $headers);
            $this->channel?->basic_publish(
                msg: $message->getOriginalMessage(),
                exchange: config('rabbitmq.exchange'),
                routing_key: $routingKey
            );
        } catch (\Throwable $e) {
            $this->logLocalErrors($e);
            captureException($e);
            return;
        }
    }

    /**
     * Resolve route data
     *
     * @param string $route
     * @return array
     */
    public function resolveRouteData(string $route) : array
    {
        if (array_key_exists('#', $this->routes)) {
            return $this->routes['#'];
        }
        return $this->routes[$route] ?? [];
    }

    /**
     * Call route
     *
     * @param AMQPMessage $message
     * @return void
     */
    public function callback(AMQPMessage $message) : void
    {
        $data = json_decode($message->body, true);
        $route = $data['x-routing-key']  ?? $message->getRoutingKey() ?? '';
        $this->setAuthUser($data);
        $routeData = $this->resolveRouteData($route);
        $action = $routeData[0] ?? null;
        $retry = $routeData[1] ?? false;
        if (!empty($action['controller']) && !empty($action['method']) && method_exists(object_or_class: $action['controller'], method: $action['method'])) {
            $this->createCallback(
                controller: $action['controller'],
                method: $action['method'],
                message: $message,
                retry: $retry)
            ;
        }
        else {
            if (env("APP_ENV") === 'local') Log::error("No callback found for route $route");
            $this->captureExceptionWithScope(new Exception("No callback found for route $route"), $data);
            $this->acknowledgeMessage($message);
        }
        $this->flush();
    }

    /**
     * Set auth user
     * uses x-user field in the message body to set the auth user
     *
     * @param array $messagePayload
     * @return void
     */
    protected function setAuthUser($messagePayload) : void {
        $user = null;
        if ($userData = $messagePayload['x-user'] ?? null) {
            try {
                $authUserCallback = app('EventAuth')?->authUserCallback;
                $user = $authUserCallback ? $authUserCallback($userData) : null;

                if ($user) RabbitMQAuth::setUser($user);
            } catch (\Throwable $e) {
                $this->logLocalErrors($e);
                captureException($e);
            }
        }
        if (!$user) RabbitMQAuth::logout();
    }

    /**
     * Set auth user callback
     *
     * This will use the implementation in EventAuthServiceProvider
     *
     * @param Closure $callback
     * @return void
     */
    public function setAuthUserCallback(Closure $callback) : void
    {
        $this->authUserCallback = $callback;
    }

    /**
     * Set serialize auth user callback
     *
     * This will use the implementation in EventAuthServiceProvider
     *
     * @param Closure $callback
     * @return void
     */
    public function setSerializeAuthUserCallback(Closure $callback) : void
    {
        $this->serializeAuthUserCallback = $callback;
    }

    /**
     * Set on fail callback
     *
     * This will use the implementation in EventFailServiceProvider
     *
     * @param Closure $callback
     * @return void
     */
    public function setOnFailCallback(Closure $callback) : void
    {
        $this->onFailCallback = $callback;
    }


    /**
     * Set user data in the message body
     *
     * The user data in the message body will be used to set the auth user when this event is consumed
     * x-user field will be set in the message body using either the current auth user from the api call or the user
     * from the message payload.
     *
     * @param array $body
     * @return array
     */
    protected function setUserData(array $body) : array
    {
        try {
            $serializeAuthUserCallback = app('EventAuth')?->serializeAuthUserCallback;
            $body['x-user'] = $serializeAuthUserCallback ? $serializeAuthUserCallback() : null;
        } catch (\Throwable $e) {
            $this->logLocalErrors($e);
            captureException($e);
        }

        return $body;
    }

    /**
     * Create action
     *
     * @param string|array $callback
     * @return array
     */
    protected function createAction(string|array $callback) : array
    {
        $action = [];
        if (is_array($callback)) {
            $action['controller'] = $this->createController(controller: $callback[0]);
            $action['method'] = $callback[1];
        }
        else if (str_contains($callback, '@')) {
            $controller = strtok(string: $callback, token: '@');
            $action['controller'] = $this->createController(controller: $controller);
            $action['method'] = strtok(string: '');
        }
        return $action;
    }

    /**
     * Create controller instance
     *
     * @param mixed $controller
     * @return mixed
     */
    protected function createController(mixed $controller) : mixed
    {
        if (class_exists($controller)) {
            return app($controller);
        }

        $controller = "App\\Amqp\\Controllers\\$controller";
        if (class_exists(class: $controller)) {
            return app($controller);
        }

        return null;
    }

    /**
     * Call action
     *
     * @param mixed $controller
     * @param mixed $method
     * @param AMQPMessage $message
     * @param bool $retry
     * @return void
     */
    protected function createCallback(mixed $controller, mixed $method, AMQPMessage $message, bool $retry = false, bool $test = false): void
    {
        try {
            $rabbitMessage = new RabbitMQMessage($message);
            $this->setCurrentMessage($rabbitMessage);
            $controller->{$method}($rabbitMessage, $rabbitMessage->identifier());
        } catch (\Throwable $e) {
            $this->logLocalErrors($e);
            $this->onFail($message, $e, $retry);
            if($test) throw $e;
        }
        if (!$test) {
            $this->acknowledgeMessage($message);
        }
    }

    /**
     * Acknowledge message
     *
     * @param AMQPMessage $message
     * @return void
     */
    protected function acknowledgeMessage(AMQPMessage $message) : void
    {
        try {
            $message->ack();
        } catch (\Throwable $e) {
            captureException($e);
        }
    }

    /**
     * @param AMQPMessage $message
     * @param Exception $e
     * @param bool $retry
     * @return void
     */
    private function onFail(AMQPMessage $message, \Throwable $e, bool $retry = false): void
    {
        $rabbitMessage = new RabbitMQMessage($message);

        try {
            $onFailCallback = app('EventFail')?->onFailCallback;
            $onFailCallback ? $onFailCallback($rabbitMessage, $e, $retry) : null;
        } catch (\Throwable $e) {
            $this->logLocalErrors($e);
            captureException($e);
        }

        $data = json_decode($message->getBody(), true);
        $this->captureExceptionWithScope($e, $data);
    }

    /**
     * Captures exception and adds event data to the scope
     *
     * @param Exception $e
     * @param array $data
     */
    private function captureExceptionWithScope(\Throwable $e, array $data): void {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e, $data) {
            if ($e instanceof ValidationException) {
                $data['x-errors'] = $e->errors();
                $scope->setContext('Errors', $e->errors());
            }
            $scope->setContext('Event', $data);
            captureException($e);
        });
    }

    /**
     * Gets current message
     * @return RabbitMQMessage
     */
    public function current(): ?RabbitMQMessage
    {
        return self::$currentMessage;
    }

    /**
     * Sets current message
     * @param RabbitMQMessage|null $currentMessage
     */
    private function setCurrentMessage(?RabbitMQMessage $currentMessage): void
    {
        self::$currentMessage = $currentMessage;
    }

    /**
     * Flushes current auth user and message
     * @return void
     */
    private function flush(): void
    {
        RabbitMQAuth::logout();
        $this->setCurrentMessage(null);
    }

    /**
     * Consume messages
     *
     * @param bool $once
     * @param string $route
     * @param RabbitMQMessage $message
     * @return void
     */
    public function test(string $route, RabbitMQMessage $message): void
    {
        $this->createCallback(
            controller: $this->routes[$route][0]['controller'],
            method: $this->routes[$route][0]['method'],
            message: $message,
            test: true
        );

    }

    /**
     * Creates a one time listener process
     *
     * @param array $options
     * @param null|string $path
     * @return Process
     */
    protected function makeProcess(array $options, ?string $path = null): Process
    {
        $command = ['php', 'artisan', 'rabbitmq:consume', '--once'];
        if (!$path && function_exists('base_path')) $path = base_path();
        return new Process(
            $command,
            $path,
            null,
            null,
            $options['timeout'] ?? 60,
        );
    }

    /**
     * Listens for messages and processes them in a separate process
     * enabling hot-reloading.
     *
     * Options:
     *      poll: The frequency in seconds to poll for messages
     *      routeRefresh: The number of polls to wait before refreshing routes
     *      timeout: The number of seconds a child process can run
     *
     * @param array $options
     * @param null|string $path
     * @return void
     */
    public function listenEvents(array $options, ?string $path = null): void
    {
        $sleep = $this->getSleep($options['poll']);

        $stalePolls = $options['routeRefresh'] ?? 50;

        $counter = 0;
        while(true) {
            if ($this->hasMessage() || ++$counter > $stalePolls) {
                $counter = 0;
                $process = $this->makeProcess($options, $path);
                $process->run();
            } else {
                usleep($sleep);
            }
        }
    }

    /**
     * Gets sleep time in microseconds with polling frequency
     *
     * @param $poll
     * @return float|int
     */
    private function getSleep($poll): int|float
    {
        $frequency = $poll ?? 10;
        $frequency = max($frequency, 1);
        return floor(1000 / $frequency) * 1000;
    }

    /**
     * @param \Throwable|Exception $e
     * @return void
     */
    private function logLocalErrors(\Throwable|Exception $e): void
    {
        if (env("APP_ENV") === 'local') {
            Log::error(get_class($e) . " at " . $e->getFile() . " line " . $e->getLine());
            Log::error($e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getTraceAsString());
        }
    }
}
