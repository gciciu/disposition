<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DriverRealtimePublisher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $internalUrl,
        private readonly string $secret,
    ) {
    }

    public function publishRouteStarted(DeliveryRoute $route): void
    {
        $routeId = $route->getId();
        if (null === $routeId) {
            return;
        }

        $this->publish(
            $this->channelsForRoute($routeId),
            'route.started',
            ['route' => $route->toArray()],
        );
    }

    public function publishOrderCompleted(DeliveryOrder $order): void
    {
        $this->publishOrderEvent('order.completed', $order);
    }

    public function publishOrderRefused(DeliveryOrder $order): void
    {
        $this->publishOrderEvent('order.refused', $order);
    }

    private function publishOrderEvent(string $event, DeliveryOrder $order): void
    {
        $route = $order->getRoute();
        if (!$route instanceof DeliveryRoute || null === $route->getId()) {
            return;
        }

        $this->publish(
            $this->channelsForRoute($route->getId()),
            $event,
            [
                'order' => $order->toArray(),
                'route' => $route->toArray(),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function channelsForRoute(int $routeId): array
    {
        return [
            'admin.routes',
            sprintf('admin.routes.%d', $routeId),
        ];
    }

    /**
     * @param list<string>         $channels
     * @param array<string, mixed> $data
     */
    private function publish(array $channels, string $event, array $data): void
    {
        $base = rtrim($this->internalUrl, '/');
        if ('' === $base || '' === $this->secret) {
            return;
        }

        try {
            $response = $this->httpClient->request('POST', $base.'/publish', [
                'headers' => [
                    'X-Realtime-Secret' => $this->secret,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'channels' => $channels,
                    'event' => $event,
                    'data' => $data,
                ],
                'timeout' => 2.0,
            ]);
            // Force the lazy response to execute immediately.
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $this->logger->warning('Realtime publish rejected with HTTP {status}', [
                    'status' => $status,
                    'event' => $event,
                ]);
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Realtime publish failed: {message}', [
                'message' => $e->getMessage(),
                'event' => $event,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Realtime publish error: {message}', [
                'message' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }
}
