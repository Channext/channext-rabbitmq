<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use App\Models\User;
use Channext\ChannextRabbitmq\Facades\RabbitMQAuth;
use Channext\ChannextRabbitmq\Models\Message;
use Closure;
use Exception;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Schema;
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
            $this->channel?->exchange_declare(exchange: config('rabbitmq.exchange'), type: 'topic', durable: true, auto_delete: false);
            $this->channel?->queue_declare(queue: config('rabbitmq.queue'), durable: true, auto_delete: false, arguments: ['x-max-priority' => array('I', 5)]);
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
        if (!array_key_exists($route, $this->routes)) {
            $this->routes[$route] = [$this->createAction(callback: $callback), $expiresIn];

            try {
                $this->channel?->queue_bind(queue: config('rabbitmq.queue'), exchange: config('rabbitmq.exchange'), routing_key: $route);
            } catch (\Exception $e) {
                captureException($e);
            }
        }
    }

    /**
     * Consume messages
     *
     * @return void
     */
    public function consume() : void
    {
        try {
            $this->channel?->basic_consume(queue: config('rabbitmq.queue'), callback: [$this, 'callback']);
            $this->channel?->consume();
        } catch (\Exception $e) {
            Log::info($e->getMessage().' '.$e->getLine().' '.$e->getTraceAsString());
            RabbitMQAuth::logout();
            captureException($e);
        }
    }

    /**
     * Publish message
     *
     * @param $body
     * @param $routingKey
     * @return void
     */
    public function publish(array $body, string $routingKey) : void
    {
        $model = $this->createModel(body: $body, routingKey: $routingKey);

        try {
            $message = $this->createMessage(body: ['x-data' => $body]);
            $this->channel?->basic_publish(msg: $message, exchange: config('rabbitmq.exchange'), routing_key: $routingKey);
        } catch (\Exception $e) {
            captureException($e);
            return;
        }

        $this->publishMessage(model: $model);
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
        [$action, $expiresIn] = $this->routes[$route] ?? [];
        if (!empty($action['controller']) && !empty($action['method'])) {
            $this->createCallback(controller: $action['controller'], method: $action['method'], message: $message, expiresIn: $expiresIn);
        }
        RabbitMQAuth::logout();
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

        $controller = "App\\Http\\EventControllers\\$controller";
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
        if (method_exists(object_or_class: $controller, method: $method)) {
            $retry = false;
            try {
                $rabbitMessage = new RabbitMQMessage($message);
                $controller->$method($rabbitMessage, $message);
            } catch (\Exception $e) {
                $retry = $this->onFail($message, $e, $expiresIn);
            }
            if (!$retry) $this->acknowledgeMessage($message);
        }
    }

    /**
     * Create message
     *
     * @param array $body
     * @param int $priority
     * @return AMQPMessage
     */
    protected function createMessage(array $body, int $priority = 2) : AMQPMessage
    {
        // add timestamp to message
        if (!isset($body['x-published-at'])) $body['x-published-at'] = time();
        if (!isset($body['x-user'])) $body = $this->setUserData($body);
        return new AMQPMessage(body: json_encode($body), properties: [
            'delivery_mode' => $this->deliveryMode,
            'priority' => $priority
        ]);
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
                'delivery_mode' => $this->deliveryMode,
                'routing_key' => $routingKey,
                'body' => $body
            ]);
        } return null;
    }

    /**
     * Publish message model
     *
     * @param $model
     * @return void
     */
    protected function publishMessage($model) : void
    {
        if ($model) {
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
    private function onFail(AMQPMessage $message, Exception $e, int $expiresIn = 0): bool
    {
        $data = json_decode($message->getBody(), true);
        $data['x-routing-key'] = $data['x-routing-key'] ?? $message->getRoutingKey() ?? '';
        $retry = $data['x-retry-state'] ?? false;
        $requeue = $expiresIn > 0 && $expiresIn + ($data['x-published-at'] ?? 0) > time();
        if ($retry && $requeue) {
            $message->nack(requeue: true);

        } else if ($requeue) {
            $data['x-retry-state'] = true;
            $newMessage = $this->createMessage(body: $data, priority: 1);
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
    private function captureExceptionWithScope(Exception $e, array $data): void {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e, $data) {
            $scope->setContext('EventData', $data);
            captureException($e);
        });
    }
}
