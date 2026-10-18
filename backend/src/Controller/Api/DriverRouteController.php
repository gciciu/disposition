<?php

namespace App\Controller\Api;

use App\Entity\Courier;
use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Entity\User;
use App\Enum\DeliveryProofType;
use App\Enum\RouteOriginType;
use App\Repository\DeliveryRouteRepository;
use App\Service\DeliveryProofService;
use App\Service\DeliveryRouteMetricsService;
use App\Service\DriverRealtimePublisher;
use App\Service\GoogleMapsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/driver/routes')]
#[IsGranted('ROLE_COURIER')]
class DriverRouteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly DeliveryProofService $proofService,
        private readonly DeliveryRouteMetricsService $metrics,
        private readonly GoogleMapsService $googleMaps,
        private readonly DriverRealtimePublisher $realtime,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_driver_routes_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $courier = $this->requireCourier($user);
        if ($courier instanceof JsonResponse) {
            return $courier;
        }

        $items = array_map(
            static fn (DeliveryRoute $route): array => $route->toListArray(),
            $this->routes->findAssignedToCourierNewestFirst($courier),
        );

        return $this->json(['routes' => $items]);
    }

    #[Route('/{id}', name: 'api_driver_routes_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $courier = $this->requireCourier($user);
        if ($courier instanceof JsonResponse) {
            return $courier;
        }

        $route = $this->routes->findOneAssignedToCourier($id, $courier);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['route' => $route->toArray()]);
    }

    #[Route('/{id}/start', name: 'api_driver_routes_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $courier = $this->requireCourier($user);
            if ($courier instanceof JsonResponse) {
                return $courier;
            }

            $route = $this->routes->findOneAssignedToCourier($id, $courier);
            if (!$route instanceof DeliveryRoute) {
                return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
            }

            if ($route->isCompleted()) {
                return $this->json(['error' => 'Route is already completed'], Response::HTTP_CONFLICT);
            }

            if ($route->isStarted()) {
                return $this->json(['error' => 'Route is already started'], Response::HTTP_CONFLICT);
            }

            $payload = $this->readJsonPayload($request);

            if (RouteOriginType::CourierLocation === $route->getOriginType()) {
                $lat = $this->parseCoordinate($payload['lat'] ?? null, 'lat');
                if ($lat instanceof JsonResponse) {
                    return $lat;
                }
                $lng = $this->parseCoordinate($payload['lng'] ?? null, 'lng');
                if ($lng instanceof JsonResponse) {
                    return $lng;
                }

                $route->setStartCoordinates($lat, $lng);
                if ($this->googleMaps->isConfigured()) {
                    $this->metrics->rebuild($route);
                }
            }

            $route->setStartedAt(new \DateTimeImmutable());
            $route->syncCompletionFromOrders();
            $this->em->flush();
            $this->realtime->publishRouteStarted($route);

            return $this->json(['route' => $route->toArray()]);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        '/{routeId}/orders/{orderId}/complete',
        name: 'api_driver_order_complete',
        methods: ['POST'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function complete(
        int $routeId,
        int $orderId,
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        try {
            $courier = $this->requireCourier($user);
            if ($courier instanceof JsonResponse) {
                return $courier;
            }

            $order = $this->findAssignedOrder($routeId, $orderId, $courier);
            if ($order instanceof JsonResponse) {
                return $order;
            }

            $this->assertRouteStarted($order->getRoute());

            $proofTypeRaw = $request->request->get('proofType');
            if (!\is_string($proofTypeRaw) || '' === $proofTypeRaw) {
                return $this->json(['error' => 'proofType is required (photo or signature)'], Response::HTTP_BAD_REQUEST);
            }

            $proofType = DeliveryProofType::tryFrom($proofTypeRaw);
            if (!$proofType instanceof DeliveryProofType) {
                return $this->json(['error' => 'proofType must be photo or signature'], Response::HTTP_BAD_REQUEST);
            }

            $file = $request->files->get('file');
            if (!$file) {
                return $this->json(['error' => 'Proof file is required (field name: file)'], Response::HTTP_BAD_REQUEST);
            }

            if (!$file->isValid()) {
                return $this->json([
                    'error' => sprintf('Proof upload failed: %s', $file->getErrorMessage()),
                ], Response::HTTP_BAD_REQUEST);
            }

            $deliveredIndexes = $this->parseDeliveredProductIndexes($request);
            if ($deliveredIndexes instanceof JsonResponse) {
                return $deliveredIndexes;
            }

            $updated = $this->proofService->completeWithProof(
                $order,
                $proofType,
                $file,
                $deliveredIndexes,
            );

            return $this->json($this->orderMutationResponse($updated));
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        '/{routeId}/orders/{orderId}/refuse',
        name: 'api_driver_order_refuse',
        methods: ['POST'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function refuse(
        int $routeId,
        int $orderId,
        #[CurrentUser] User $user,
    ): JsonResponse {
        try {
            $courier = $this->requireCourier($user);
            if ($courier instanceof JsonResponse) {
                return $courier;
            }

            $order = $this->findAssignedOrder($routeId, $orderId, $courier);
            if ($order instanceof JsonResponse) {
                return $order;
            }

            $this->assertRouteStarted($order->getRoute());

            $updated = $this->proofService->refuse($order);

            return $this->json($this->orderMutationResponse($updated));
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    #[Route(
        '/{routeId}/orders/{orderId}/proof',
        name: 'api_driver_order_proof',
        methods: ['GET'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function proof(
        int $routeId,
        int $orderId,
        #[CurrentUser] User $user,
    ): Response {
        $courier = $this->requireCourier($user);
        if ($courier instanceof JsonResponse) {
            return $courier;
        }

        $order = $this->findAssignedOrder($routeId, $orderId, $courier);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $path = $this->proofService->absoluteProofPath($order);
        if (null === $path) {
            return $this->json(['error' => 'Proof not found'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $order->getProofMime() ?: 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));

        return $response;
    }

    /**
     * @return list<int>|null|JsonResponse null means "all products" (legacy clients)
     */
    private function parseDeliveredProductIndexes(Request $request): array|JsonResponse|null
    {
        if (!$request->request->has('deliveredProductIndexes')) {
            return null;
        }

        $raw = $request->request->get('deliveredProductIndexes');

        if (\is_string($raw)) {
            $trimmed = trim($raw);
            if ('' === $trimmed) {
                return [];
            }
            try {
                $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->json(
                    ['error' => 'deliveredProductIndexes must be a JSON array of integers'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $raw = $decoded;
        }

        if (!\is_array($raw)) {
            return $this->json(
                ['error' => 'deliveredProductIndexes must be a JSON array of integers'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $indexes = [];
        foreach ($raw as $item) {
            if (!\is_int($item) && !(\is_string($item) && ctype_digit($item))) {
                return $this->json(
                    ['error' => 'deliveredProductIndexes must be a JSON array of integers'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $indexes[] = (int) $item;
        }

        return $indexes;
    }

    private function findAssignedOrder(int $routeId, int $orderId, Courier $courier): DeliveryOrder|JsonResponse
    {
        $route = $this->routes->findOneAssignedToCourier($routeId, $courier);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        foreach ($route->getOrders() as $order) {
            if ($order->getId() === $orderId) {
                return $order;
            }
        }

        return $this->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
    }

    private function assertRouteStarted(?DeliveryRoute $route): void
    {
        if (!$route instanceof DeliveryRoute || !$route->isStarted()) {
            throw new ConflictHttpException('Route must be started before completing or refusing deliveries');
        }

        if ($route->isCompleted()) {
            throw new ConflictHttpException('Route is already completed');
        }
    }

    /**
     * @return array{order: array<string, mixed>, route: array<string, mixed>}
     */
    private function orderMutationResponse(DeliveryOrder $order): array
    {
        $route = $order->getRoute();

        return [
            'order' => $order->toArray(),
            'route' => $route instanceof DeliveryRoute ? $route->toArray() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        try {
            $decoded = $request->toArray();
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function parseCoordinate(mixed $value, string $field): float|JsonResponse
    {
        if (\is_int($value) || \is_float($value)) {
            $number = (float) $value;
        } elseif (\is_string($value) && is_numeric($value)) {
            $number = (float) $value;
        } else {
            return $this->json(
                ['error' => sprintf('%s is required and must be a number', $field)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ('lat' === $field && ($number < -90.0 || $number > 90.0)) {
            return $this->json(['error' => 'lat must be between -90 and 90'], Response::HTTP_BAD_REQUEST);
        }

        if ('lng' === $field && ($number < -180.0 || $number > 180.0)) {
            return $this->json(['error' => 'lng must be between -180 and 180'], Response::HTTP_BAD_REQUEST);
        }

        return $number;
    }

    private function requireCourier(User $user): Courier|JsonResponse
    {
        $courier = $user->getCourier();
        if (!$courier instanceof Courier) {
            return $this->json(['error' => 'Courier profile not found'], Response::HTTP_FORBIDDEN);
        }

        return $courier;
    }
}
