<?php

namespace Channext\ChannextRabbitmq\RabbitMQ;

use Illuminate\Support\Arr;
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
        return $this->decodedBody['x-retry-state'] ?? false;
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
        return $this->decodedBody[$key] ?? null;
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

        return  $headers;
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
     * @throws ValidationException
     * @return array
     */
    private function replaceValidated(array $validated, array $replacements): array
    {
        foreach ($replacements as $key => $replacement) {
            if(Arr::get($validated, $replacement) !== null) continue;
            $value = Arr::get($validated, $key);
            Arr::set($validated , $replacement, $value);
            Arr::forget($validated , $key, $value);
        }
        return $validated;
    }
}
