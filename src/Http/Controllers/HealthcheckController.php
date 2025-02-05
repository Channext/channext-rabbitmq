<?php

declare(strict_types=1);

namespace Channext\ChannextRabbitmq\Http\Controllers;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use function Sentry\captureException;

class HealthcheckController
{
    /**
     * Healthcheck endpoint
     *
     * @return JsonResponse
     */
    public function healthcheck(): JsonResponse
    {
        $appIp = gethostbyname(gethostname());
        $url = config('rabbitmq.api') . '/queues/%2f/' . config('rabbitmq.queue');
        try {
            $response = Http::withBasicAuth(config('rabbitmq.user'), config('rabbitmq.password'))
                ->get($url);
        }
        catch (ConnectionException $e) {
            captureException($e);
            return response()->json([
                'status' => 'unknown',
                'message' => 'Message broker is down.'
            ], 200);
        } catch (\Throwable $e) {
            captureException($e);
            return response()->json(['status' => 'error'], 500);
        }
        $consumers = $response->json()['consumer_details'] ?? [];
        $consumerFound = false;
        foreach ($consumers as $consumer) {
            if (($consumer['channel_details']['peer_host'] ?? null) === $appIp) {
                $consumerFound = true;
                break;
            }
        }
        if ($consumerFound) {
            return response()->json(['status' => 'Healthy'], 200);
        }
        return response()->json(['status' => 'Missing consumer'], 500);
    }
}
