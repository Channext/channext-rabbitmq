<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Channext\ChannextRabbitmq\Facades\RabbitMQ;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQMessage extends AMQPMessage
{
    private array $decodedBody;
    private AMQPMessage $originalMessage;
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
        return $this->decodedBody['x-retry-state'] ?? false;
    }

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
     * @param $rules
     * @return array
     */
    public function validate(array $rules, array $messages = []) : mixed
    {
        $validator = Validator::make($this->all(), $rules);
        if($validator->fails()) {
            $routingKey = $this->getRoutingKey();
            $errors = $validator->errors()->all();
            RabbitMQ::publish(body: $errors, routingKey: "$routingKey.failed");
            return throw ValidationException::withMessages($errors);
        }

        return $validator->validated();
    }
}
