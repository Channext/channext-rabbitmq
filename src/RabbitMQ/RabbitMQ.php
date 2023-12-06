<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Channext\ChannextRabbitmq\Models\Message;
use Exception;
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
     * Define routing key and bind to queue
     *
     * @param $route
     * @param $callback
     * @return void
     */
    public function route($route, $callback, $requeue = false, $expiresIn = 0)
    {
        if (!array_key_exists($route, $this->routes)) {
            $this->routes[$route] = [$this->createAction(callback: $callback), $requeue, $expiresIn];

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
    public function consume()
    {
        try {
            $this->channel?->basic_consume(queue: config('rabbitmq.queue'), callback: [$this, 'callback']);
            $this->channel?->consume();
        } catch (\Exception $e) {
            Log::info($e->getMessage().' '.$e->getLine().' '.$e->getTraceAsString());
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
    public function publish($body, $routingKey)
    {
        $model = $this->createModel(body: $body, routingKey: $routingKey);

        try {
            $message = $this->createMessage(body: $body);
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
     * @param $message
     * @return mixed
     */
    public function callback($message)
    {
        $route = json_decode($message->body, true)['x-routing-key'] ?? $message->delivery_info['routing_key'] ?? '';
        [$action, $expiresIn] = $this->routes[$route] ?? [];
        if (!empty($action['controller']) && !empty($action['method'])) {
            return $this->createCallback(controller: $action['controller'], method: $action['method'], message: $message, expiresIn: $expiresIn);
        }
    }

    /**
     * Create action
     *
     * @param $callback
     * @return array
     */
    protected function createAction($callback)
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
     * @param $controller
     * @return string
     */
    protected function createController($controller)
    {
        if (class_exists($controller)) {
            return app($controller);
        }

        $controller = "App\\Http\\Controllers\\$controller";
        if (class_exists(class: $controller)) {
            return app($controller);
        }
        return null;
    }

    /**
     * Call action
     *
     * @param $controller
     * @param $method
     * @return mixed
     */
    protected function createCallback($controller, $method, $message, $expiresIn = 0)
    {
        if (method_exists(object_or_class: $controller, method: $method)) {
            $retry = false;
            try {
                $controller->$method($message);
            } catch (\Exception $e) {
                $retry = $this->onFail($message, $e, $expiresIn);
            }
            if (!$retry) $this->acknowledgeMessage($message);
        }
    }

    /**
     * Create message
     *
     * @param $body
     * @return AMQPMessage
     */
    protected function createMessage($body, $priority = 2)
    {
        // add timestamp to message
        if (!isset($body['x-published-at'])) $body['x-published-at'] = time();
        $body = $this->setUserData($body);
        return new AMQPMessage(body: json_encode($body), properties: [
            'delivery_mode' => $this->deliveryMode,
            'priority' => $priority
        ]);
    }

    protected function setUserData($body)
    {
        $user = Auth::user();
        $data = null;
        if ($user) $data = [
            'id' => $user->id,
            'type' => $user->type,
            'role' => $user->role,
            'org' => $user->vendor_id ?? $user->reseller_id ?? $user->distributor_id ?? $user->marketing_agency_id ?? null
        ];
        $body['x-user'] = $body['x-user'] ?? $data;
        return $body;
    }

    /**
     * Create model for message
     *
     * @param $body
     * @param $routingKey
     * @return Message
     */
    protected function createModel($body, $routingKey)
    {
        if (Schema::hasTable('messages')) {
            return Message::create([
                'user_id' => Auth::id(),
                'delivery_mode' => $this->deliveryMode,
                'routing_key' => $routingKey,
                'body' => $body
            ]);
        }
    }

    /**
     * Publish message model
     *
     * @param $model
     * @return void
     */
    protected function publishMessage($model)
    {
        if ($model) {
            $model->published = true;
            $model->save();
        }
    }

    /**
     * Acknowledge message
     *
     * @param $message
     * @return void
     */
    protected function acknowledgeMessage($message)
    {
        try {
            $message->ack();
        } catch (\Exception $e) {
            captureException($e);
        }
    }

    /**
     * @param $message
     * @param Exception $e
     * @return bool
     */
    private function onFail($message, Exception $e, int $expiresIn = 0): bool
    {
        $data = json_decode($message->getBody(), true);
        $data['x-routing-key'] = $data['x-routing-key'] ?? $message->delivery_info['routing_key'] ?? '';
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

    private function captureExceptionWithScope(Exception $e, array $data): void {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e, $data) {
            $scope->setContext('EventData', $data);
            captureException($e);
        });
    }
}
