<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use PhpAmqpLib\Message\AMQPMessage;

class RabbitMessage extends AMQPMessage
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
        $data = $this->decodedBody['x-data'];
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
}
