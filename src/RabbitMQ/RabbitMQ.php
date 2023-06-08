<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Channext\ChannextRabbitmq\Models\Message;
use Exception;
use Illuminate\Support\Facades\Auth;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Schema;

class RabbitMQ
{
    /**
     * @var ?AMQPStreamConnection $connection
     */
    private ?AMQPStreamConnection $connection;

    /**
     * @var ?AMQPChannel $channel
     */
    private ?AMQPChannel $channel;

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
                host: config('services.rabbitmq.host'),
                port: config('services.rabbitmq.port'),
                user: config('services.rabbitmq.user'),
                password: config('services.rabbitmq.password')
            );
            $this->channel = $this->connection?->channel();
            $this->channel?->exchange_declare(exchange: config('services.rabbitmq.exchange'), type: 'topic', durable: true, auto_delete: false);
            $this->channel?->queue_declare(queue: config('services.rabbitmq.queue'), durable: true, auto_delete: false);
        } catch (\Exception $e) {
            \Sentry\captureException($e);
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
            \Sentry\captureException($e);
        }
    }

    /**
     * Define routing key and bind to queue
     *
     * @param $route
     * @param $callback
     * @return void
     */
    public function route($route, $callback)
    {
        if (!array_key_exists($route, $this->routes)) {
            $this->routes[$route] = $this->createAction(callback: $callback);

            try {
                $this->channel->queue_bind(queue: config('services.rabbitmq.queue'), exchange: config('services.rabbitmq.exchange'), routing_key: $route);
            } catch (\Exception $e) {
                \Sentry\captureException($e);
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
            $this->channel->basic_consume(queue: config('services.rabbitmq.queue'), callback: [$this, 'callback']);
            $this->channel->consume();
        } catch (\Exception $e) {
            \Sentry\captureException($e);
        }
    }

    /**
     * Publish message
     *
     * @param $body
     * @param $object
     * @param $operation
     * @return void
     */
    public function publish($body, $object, $operation)
    {
        $routingKey = "$object.$operation";
        $model = $this->createModel(body: $body, routingKey: $routingKey);

        try {
            $message = $this->createMessage(body: $body);
            $this->channel->basic_publish(msg: $message, exchange: config('services.rabbitmq.exchange'), routing_key: $routingKey);
        } catch (\Exception $e) {
            \Sentry\captureException($e);
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
        $route = $message->delivery_info['routing_key'] ?? '';
        $action = $this->routes[$route] ?? [];
        if (!empty($action['controller']) && !empty($action['method'])) {
            return $this->createCallback(controller: $action['controller'], method: $action['method'], message: $message);
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
    protected function createCallback($controller, $method, $message)
    {
        if (method_exists(object_or_class: $controller, method: $method)) {
            $controller->$method($message);
            $this->acknowledgeMessage($message);
        }
    }

    /**
     * Create message
     *
     * @param $body
     * @return AMQPMessage
     */
    protected function createMessage($body)
    {
        return new AMQPMessage(body: json_encode($body), properties: [
            'delivery_mode' => $this->deliveryMode,
        ]);
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
            \Sentry\captureException($e);
        }
    }
}