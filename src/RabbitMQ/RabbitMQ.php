<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use App\Models\User;
use Channext\ChannextRabbitmq\Facades\RabbitMQAuth;
use Channext\ChannextRabbitmq\Models\Message;
use Closure;
use ErrorException;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Generator\RandomBytesGenerator;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            captureException($e);
        }
    }

    /**
     * Define routing key and bind it to a queue
     *
     * @param string $route
     * @param string $callback
     * @param int $expiresIn
     * @return void
     */
    public function route(string $route, string $callback, int $expiresIn = 0) : void
    {
        if (array_key_exists('#', $this->routes)) {
            if (env("APP_ENV") === 'local') Log::warning("An universal route already exists. No specific routes can be added.");
            return;
        }
        if (!array_key_exists($route, $this->routes)) {
            $this->routes[$route] = [$this->createAction(callback: $callback), $expiresIn];

            try {
                $this->channel?->queue_bind(
                    queue: config('rabbitmq.queue'),
                    exchange: config('rabbitmq.exchange'),
                    routing_key: $route
                );
            } catch (\Exception $e) {
                captureException($e);
            }
        }
    }

    /**
     * Define a universal event listener
     *
     * @param string $callback
     * @param int $expiresIn
     * @return void
     */
    public function universal(string $callback, int $expiresIn = 0) : void
    {
        $this->route(route: '#', callback: $callback, expiresIn: $expiresIn);
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
        } catch (\Exception $e) {
            if (env("APP_ENV") === 'local') Log::error($e->getMessage().' '.$e->getLine().' '.$e->getTraceAsString());
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
     * Publish message
     *
     * @param array $body
     * @param string $routingKey
     * @param string|int|null $identifier
     * @return void
     */
    public function publish(array $body, string $routingKey, string|int $identifier = null) : void
    {
        try {
            $message = $this->createMessage(
                body: ['x-data' => $body, 'x-identifier' => $identifier],
                routingKey: $routingKey
            );
            $this->channel?->basic_publish(
                msg: $message,
                exchange: config('rabbitmq.exchange'),
                routing_key: $routingKey
            );
        } catch (\Exception $e) {
            if (env("APP_ENV") === 'local') Log::error($e->getMessage().' '.$e->getLine().' '.$e->getTraceAsString());
            captureException($e);
            return;
        }

        $this->publishMessage(message: $message);
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
        $expiresIn = $routeData[1] ?? 0;
        if (!empty($action['controller']) && !empty($action['method']) && method_exists(object_or_class: $action['controller'], method: $action['method'])) {
            $this->createCallback(
                controller: $action['controller'],
                method: $action['method'],
                message: $message,
                expiresIn: $expiresIn)
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
            $authUserCallback = app('EventAuth')?->authUserCallback;
            $user = $authUserCallback ? $authUserCallback($userData) : null;

            if ($user) RabbitMQAuth::setUser($user);
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
     * Create action
     *
     * @param $callback
     * @return array
     */
    protected function createAction(string $callback) : array
    {
        $action = [];
        if (str_contains($callback, '@')) {
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
     * @param int $expiresIn
     * @return void
     */
    protected function createCallback(mixed $controller, mixed $method, AMQPMessage $message, int $expiresIn = 0) : void
    {
        $retry = false;
        try {
            $rabbitMessage = new RabbitMQMessage($message);
            $this->setCurrentMessage($rabbitMessage);
            $controller->$method($rabbitMessage, $rabbitMessage->identifier());
        } catch (\Throwable $e) {
            if (env("APP_ENV") === 'local') {
                Log::error(get_class($e). " at " . $e->getFile() . " line " . $e->getLine());
                Log::error($e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getTraceAsString());
            }
            $retry = $this->onFail($message, $e, $expiresIn);
        }
        if (!$retry) $this->acknowledgeMessage($message);
    }

    /**
     * Create message
     *
     * @param array $body
     * @param int $priority
     * @return AMQPMessage
     */
    protected function createMessage(array $body, string $routingKey, int $priority = 2, $retry = false) : AMQPMessage
    {
        $body = $this->addHeaders($routingKey, $body, $retry);
        if (!$retry) {
            $this->createModel(body: $body, routingKey: $routingKey);
        }
        $encoded = json_encode($body);
        return new AMQPMessage(body: $encoded, properties: [
            'delivery_mode' => $this->deliveryMode,
            'priority' => $priority
        ]);
    }

    /**
     * @param string $routingKey
     * @param array $body
     * @param mixed $retry
     * @return array
     */
    private function addHeaders(string $routingKey, array $body, mixed $retry): array
    {
        $body['x-routing-key'] = $routingKey;
        // add timestamp to message
        if (!isset($body['x-published-at'])) $body['x-published-at'] = time();
        if (!isset($body['x-user'])) $body = $this->setUserData($body);
        $trace = [];
        if (!$retry && static::$currentMessage) {
            $trace = static::$currentMessage->getTrace();
            $trace[] = "[".date('Y-m-d\TH:i:s') . substr(microtime(), 1, 8)
                . date('P') . "]  :  " . static::$currentMessage->getTraceId();
        }
        $body['x-trace'] = $trace;
        // x-trace-id is used to trace the message
        $encoded = json_encode($body);
        if (!isset($body['x-trace-id'])) $body['x-trace-id'] = $this->safeUuid($encoded);
        $body['x-origin'] = env('APP_NAME', env('RABBITMQ_QUEUE', 'unknown'));
        return $body;
    }

    private function safeUuid(string $seed): string
    {
        $seed .= microtime();
        $uuidFactory = new UuidFactory();
        $uuidFactory->setRandomGenerator(new RandomBytesGenerator($seed));
        Uuid::setFactory($uuidFactory);
        return Uuid::uuid4()->toString();
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
        $user = Auth::user() ?? RabbitMQAuth::user();
        if ($user) $body['x-user'] = [
            'id' => $user->id,
            'type' => $user->type,
            'role' => $user->role,
            'org' => $user->vendor_id ?? $user->reseller_id ?? $user->distributor_id ?? $user->marketing_agency_id ?? null
        ];
        return $body;
    }

    /**
     * Create model for message
     *
     * @param array $body
     * @param string $routingKey
     * @return Message|null
     */
    protected function createModel(array $body, string $routingKey) : ?Message
    {
        if (Schema::hasTable('messages')) {
            return Message::create([
                'user_id' => Auth::id(),
                'trace_id' => $body['x-trace-id'],
                'delivery_mode' => $this->deliveryMode,
                'routing_key' => $routingKey,
                'body' => $body['x-data'],
                'trace' => $body['x-trace'] ?? [],
            ]);
        } return null;
    }

    /**
     * Publish message model
     *
     * @param $model
     * @return void
     */
    protected function publishMessage(AMQPMessage $message) : void
    {
        if(!Schema::hasTable('messages')) return;
        $traceId = json_decode($message->getBody(),true)['x-trace-id'] ?? null;
        if ($traceId && $model = Message::where('trace_id', json_decode($traceId))->first()) {
            $model->published = true;
            $model->save();
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
        } catch (\Exception $e) {
            captureException($e);
        }
    }

    /**
     * @param AMQPMessage $message
     * @param Exception $e
     * @param int $expiresIn
     * @return bool
     */
    private function onFail(AMQPMessage $message, \Throwable $e, int $expiresIn = 0): bool
    {
        $data = json_decode($message->getBody(), true);
        $routingKey = $data['x-routing-key'] ?? $message->getRoutingKey() ?? '';
        $retry = $data['x-retry-state'] ?? false;
        $requeue = $expiresIn > 0 && $expiresIn + ($data['x-published-at'] ?? 0) > time();
        if ($retry && $requeue) {
            $message->nack(requeue: true);

        } else if ($requeue) {
            $data['x-retry-state'] = true;
            $newMessage = $this->createMessage(body: $data, routingKey: $routingKey, priority: 1, retry: true);
            $this->channel?->basic_publish(msg: $newMessage, routing_key: config('rabbitmq.queue'));

            $this->captureExceptionWithScope($e, $data);
        } else {
            $this->captureExceptionWithScope($e, $data);
        }
        return $retry && $requeue;
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
}
