<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Channext\ChannextRabbitmq\Exceptions\EventLoopException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use \Channext\ChannextRabbitmq\Facades\RabbitMQ as RabbitMQFacade;

class RabbitMQMessage extends AMQPMessage
{
    private array $decodedBody;
    private AMQPMessage $originalMessage;
    private static int $priority = 3;
    public function __construct(AMQPMessage $message)
    {
        parent::__construct(
            $message->getBody(),
            $message->get_properties()
        );

        $this->originalMessage = $message;
        $this->decodedBody = json_decode($message->getBody(), true);
    }

    /**
     * @param array $data
     * @param ?int $priority
     * @return AMQPMessage
     */
    private static function setPayload(array $data, ?int $priority = null): AMQPMessage
    {
        $priority = $priority ?? self::$priority;
        $encoded = json_encode($data);
        return new AMQPMessage(body: $encoded, properties: [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority' => $priority
        ]);
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        return print_r([
            'body' => $this->all(),
            'headers' => $this->headers(),
        ], true);
    }

    /**
     * @return AMQPMessage
     */
    public function getOriginalMessage() : AMQPMessage
    {
        return $this->originalMessage;
    }

    /**
     * @return array
     */
    public function all() : array
    {
        return $this->decodedBody['x-data'];
    }

    /**
     * @param $keys
     * @return array
     */
    public function only($keys) : array
    {
        $data = $this->decodedBody['x-data'];
        return array_intersect_key($data, array_flip((array) $keys));
    }

    /**
     * @param $keys
     * @return array
     */
    public function except($keys) : array
    {
        $data = $this->decodedBody['x-data'];
        return array_diff_key($data, array_flip((array) $keys));
    }

    /**
     * @param $key
     * @param $default
     * @return mixed
     */
    public function get($key, $default = null) : mixed
    {
        $data = $this->decodedBody['x-data'] ?? [];
        return $data[$key] ?? $default;
    }

    /**
     * @return array|null
     */
    public function user() : ?array
    {
        return $this->decodedBody['x-user'] ?? null;
    }

    /**
     * @return string|int|null
     */
    public function identifier() : string|int|null
    {
        return $this->decodedBody['x-identifier'] ?? null;
    }

    /**
     * @return string|null
     */
    public function publishedAt() : ?string
    {
        return $this->decodedBody['x-published-at'] ?? null;
    }

    /**
     * @return string|null
     */
    public function getRoutingKey() : ?string
    {
        return $this->decodedBody['x-routing-key'] ?? $this->originalMessage->getRoutingKey();
    }

    /**
     * @return bool
     */
    public function isRetried() : bool
    {
        return (bool) ($this->decodedBody['x-retry-state'] ?? 0);
    }

    /**
     * @return string|null
     */
    public function getTraceId() : ?string
    {
        return $this->decodedBody['x-trace-id'] ?? null;
    }

    /**
     * @return array
     */
    public function getTrace() : array
    {
        return $this->decodedBody['x-trace'] ?? [];
    }

    /**
     * @param $key
     * @param $default
     * @return mixed
     */
    public function header($key = null, $default = null) : mixed
    {
        return $this->decodedBody[$key] ?? $default;
    }

    /**
     * @return array
     */
    public function headers() : array
    {
        $headers = [];

        if (isset($this->decodedBody['x-user'])) $headers['x-user'] = $this->decodedBody['x-user'];
        if (isset($this->decodedBody['x-identifier'])) $headers['x-identifier'] = $this->decodedBody['x-identifier'];
        if (isset($this->decodedBody['x-published-at'])) $headers['x-published-at'] = $this->decodedBody['x-published-at'];
        if (isset($this->decodedBody['x-routing-key'])) $headers['x-routing-key'] = $this->decodedBody['x-routing-key'];
        if (isset($this->decodedBody['x-retry-state'])) $headers['x-retry-state'] = $this->decodedBody['x-retry-state'];
        if (isset($this->decodedBody['x-trace-id'])) $headers['x-trace-id'] = $this->decodedBody['x-trace-id'];
        if (isset($this->decodedBody['x-trace'])) $headers['x-trace'] = $this->decodedBody['x-trace'];
        if (isset($this->decodedBody['x-origin'])) $headers['x-origin'] = $this->decodedBody['x-origin'];
        if (isset($this->decodedBody['x-priority'])) $headers['x-priority'] = $this->decodedBody['x-priority'];

        return  $headers;
    }

//    public function set

    /**
     * @return int
     */
    public function getDeliveryTag() : int
    {
        return $this->originalMessage->getDeliveryTag();
    }

    /**
     * @return string
     */
    public function getConsumerTag() : string
    {
        return $this->originalMessage->getConsumerTag();
    }

    /**
     * @return bool
     */
    public function isRedelivered() : bool
    {
        return $this->originalMessage->isRedelivered();
    }

    /**
     * @return string
     */
    public function getExchange() : string
    {
        return $this->originalMessage->getExchange();
    }

    /**
     * @return int
     */
    public function getMessageCount() : int
    {
        return $this->originalMessage->getMessageCount();
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel() : AMQPChannel
    {
        return $this->originalMessage->getChannel();
    }

    /**
     * @return array
     */
    public function getDeliveryInfo() : array
    {
        return $this->originalMessage->getDeliveryInfo();
    }

    /**
     * @param array $rules
     * @param array $replacements
     * @throws ValidationException
     * @return array
     */
    public function validate(array $rules, array $replacements = []): array
    {
        $validator = Validator::make($this->all(), $rules);
        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            return throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        return $this->replaceValidated($validated, $replacements);
    }

    /**
     * @param array $validated
     * @param array $replacements
     * @return array
     */
    private function replaceValidated(array $validated, array $replacements): array
    {
        foreach ($replacements as $key => $replacement) {
            if(!Arr::has($validated, $key)) continue;
            if(Arr::get($validated, $replacement) !== null) continue;
            $value = Arr::get($validated, $key);
            Arr::set($validated , $replacement, $value);
            Arr::forget($validated , $key, $value);
        }
        return $validated;
    }

    /**
     * Make a new RabbitMQMessage
     *
     * @param string $routingKey
     * @param array $data
     * @param array $headers
     * @param int|null $priority
     * @return RabbitMQMessage
     * @throws EventLoopException
     */
    public static function make(string $routingKey, array $data, array $headers = [], ?int $priority = null): RabbitMQMessage
    {

        $headers = self::addHeaders($routingKey, $headers);
        $headers['x-data'] = $data;
        if ($priority) $headers['x-priority'] = $priority;
        $message = self::setPayload($headers, $priority);
        return new RabbitMQMessage($message);
    }

    /**
     * set x-routing-key header data
     *
     * @param string $routingKey
     * @return void
     */
    public function setRouteKey(string $routingKey): void
    {
        $data = $this->decodedBody;
        $data['x-routing-key'] = $routingKey;
        $this->decodedBody = $data;
        self::setPayload($data, $data['x-priority'] ?? null);
    }


    /**
     * set x-user header data
     *
     * @param array $userData
     * @return void
     */
    public function setUserData(array $userData): void
    {
        $data = $this->decodedBody;
        $data['x-user'] = $userData;
        $this->decodedBody = $data;
        self::setPayload($data, $data['x-priority'] ?? null);
    }


    /**
     * @param string $routingKey
     * @param array $body
     * @return array
     * @throws EventLoopException
     */
    private static function addHeaders(string $routingKey, array $body): array
    {
        $body['x-routing-key'] = $routingKey;
        // add timestamp to message
        if (!isset($body['x-published-at'])) $body['x-published-at'] = floor(microtime(true) * 1000);
        $retry = (bool) ($body['x-retry-state'] ?? 0);
        $trace = [];
        if (!$retry && RabbitMQFacade::current()) {
            $trace = RabbitMQFacade::current()->getTrace();
            $trace[RabbitMQFacade::current()->getRoutingKey()] = RabbitMQFacade::current()->getTraceId();
        }
        else if ($retry) {
            $trace = $body['x-trace'] ?? [];
        }

        if (array_key_exists($routingKey, $trace)) {
            throw new EventLoopException("Routing key $routingKey already exists in trace");
        }

        $body['x-trace'] = $trace;
        // x-trace-id is used to trace the message
        if (!isset($body['x-trace-id'])) $body['x-trace-id'] = Uuid::uuid7()->toString();
        $body['x-origin'] = env('APP_NAME', env('RABBITMQ_QUEUE', 'unknown'));
        return $body;
    }

}
