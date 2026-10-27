<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;

class DriverRealtimePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publishRouteStarted(DeliveryRoute $route): void
    {
        $routeId = $route->getId();
        if (null === $routeId) {
            return;
        }

        $this->publish(
            $this->topicsForRoute($routeId),
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
            $this->topicsForRoute($route->getId()),
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
    private function topicsForRoute(int $routeId): array
    {
        return [
            'admin.routes',
            sprintf('admin.routes.%d', $routeId),
        ];
    }

    /**
     * @param list<string>         $topics
     * @param array<string, mixed> $data
     */
    private function publish(array $topics, string $event, array $data): void
    {
        try {
            $payload = json_encode(
                [
                    'event' => $event,
                    'data' => $data,
                ],
                \JSON_THROW_ON_ERROR,
            );

            $this->hub->publish(new Update($topics, $payload, true));
        } catch (MercureRuntimeException|\JsonException|\Throwable $e) {
            $this->logger->warning('Realtime publish failed: {message}', [
                'message' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }
}
