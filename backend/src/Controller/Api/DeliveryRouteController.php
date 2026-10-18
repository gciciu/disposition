<?php

namespace App\Controller\Api;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Enum\RouteOriginType;
use App\Exception\RouteAddressValidationException;
use App\Repository\CourierRepository;
use App\Repository\DeliveryRouteRepository;
use App\Repository\WarehouseRepository;
use App\Service\DeliveryProofService;
use App\Service\DeliveryRouteMetricsService;
use App\Service\GoogleMapsService;
use App\Service\RoutePdfImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/routes')]
#[IsGranted('ROLE_ADMIN')]
class DeliveryRouteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly CourierRepository $couriers,
        private readonly WarehouseRepository $warehouses,
        private readonly RoutePdfImportService $importer,
        private readonly GoogleMapsService $googleMaps,
        private readonly DeliveryRouteMetricsService $metrics,
        private readonly DeliveryProofService $proofService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_admin_routes_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(
            static fn (DeliveryRoute $route): array => $route->toListArray(),
            $this->routes->findAllNewestFirst(),
        );

        return $this->json(['routes' => $items]);
    }

    #[Route('/import', name: 'api_admin_routes_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json([
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'PDF file is required (field name: file)'], Response::HTTP_BAD_REQUEST);
        }

        $mime = (string) $file->getMimeType();
        $original = strtolower((string) $file->getClientOriginalName());
        $isPdf = str_ends_with($original, '.pdf')
            || \in_array($mime, ['application/pdf', 'application/x-pdf'], true);

        if (!$isPdf) {
            return $this->json(['error' => 'Only PDF files are supported'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $route = $this->importer->import($file);
        } catch (RouteAddressValidationException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'invalidAddresses' => $e->getInvalidAddresses(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'GOOGLE_MAPS_API_KEY')) {
                return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $this->json(['error' => 'Import failed: '.$e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: '.$e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $this->em->persist($route);
        $this->em->flush();

        return $this->json([
            'route' => $route->toArray(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_routes_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->googleMaps->isConfigured()) {
            $this->metrics->rebuild($route);
            $this->em->flush();
        }

        return $this->json(['route' => $route->toArray()]);
    }

    #[Route(
        '/{routeId}/orders/{orderId}/proof',
        name: 'api_admin_order_proof',
        methods: ['GET'],
        requirements: ['routeId' => '\d+', 'orderId' => '\d+'],
    )]
    public function proof(int $routeId, int $orderId): Response
    {
        $route = $this->routes->find($routeId);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $order = null;
        foreach ($route->getOrders() as $candidate) {
            if ($candidate->getId() === $orderId) {
                $order = $candidate;
                break;
            }
        }

        if (!$order instanceof DeliveryOrder) {
            return $this->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
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

    #[Route('/{id}', name: 'api_admin_routes_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $previousOriginType = $route->getOriginType();
        $previousWarehouseId = $route->getWarehouse()?->getId();
        $previousCourierId = $route->getCourier()?->getId();
        $originChanged = false;

        if ($route->isStarted()) {
            $changingCourier = \array_key_exists('courierId', $payload)
                && $this->normalizeNullableId($payload['courierId']) !== $previousCourierId;
            $changingOrigin = (\array_key_exists('originType', $payload) || \array_key_exists('warehouseId', $payload))
                && $this->wouldChangeOrigin($route, $payload);

            if ($changingCourier || $changingOrigin) {
                return $this->json([
                    'error' => 'Courier and warehouse cannot be changed after the route has started',
                ], Response::HTTP_CONFLICT);
            }
        }

        if (\array_key_exists('courierId', $payload)) {
            $courierResult = $this->resolveCourier($payload['courierId']);
            if ($courierResult instanceof JsonResponse) {
                return $courierResult;
            }
            $route->setCourier($courierResult);
        }

        if (\array_key_exists('originType', $payload) || \array_key_exists('warehouseId', $payload)) {
            $originResult = $this->applyOrigin($route, $payload);
            if ($originResult instanceof JsonResponse) {
                return $originResult;
            }
            $originChanged = $previousOriginType !== $route->getOriginType()
                || $previousWarehouseId !== $route->getWarehouse()?->getId();
        }

        if ($originChanged && $this->googleMaps->isConfigured()) {
            $this->metrics->rebuild($route);
        }

        $this->em->flush();

        return $this->json(['route' => $route->toArray()]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function wouldChangeOrigin(DeliveryRoute $route, array $payload): bool
    {
        $originTypeRaw = $payload['originType'] ?? null;
        $warehouseId = $payload['warehouseId'] ?? null;

        if (\is_string($originTypeRaw) && '' !== $originTypeRaw) {
            $originType = RouteOriginType::tryFrom($originTypeRaw);
            if (null === $originType) {
                return true;
            }
        } elseif (null !== $warehouseId) {
            $originType = RouteOriginType::Warehouse;
        } else {
            $originType = RouteOriginType::None;
        }

        if ($originType !== $route->getOriginType()) {
            return true;
        }

        if (RouteOriginType::Warehouse === $originType) {
            return $this->normalizeNullableId($warehouseId) !== $route->getWarehouse()?->getId();
        }

        return false;
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyOrigin(DeliveryRoute $route, array $payload): ?JsonResponse
    {
        $originTypeRaw = $payload['originType'] ?? null;
        $warehouseId = $payload['warehouseId'] ?? null;

        if (\is_string($originTypeRaw) && '' !== $originTypeRaw) {
            $originType = RouteOriginType::tryFrom($originTypeRaw);
            if (null === $originType) {
                return $this->json(['error' => 'originType is invalid'], Response::HTTP_BAD_REQUEST);
            }
        } elseif (null !== $warehouseId) {
            $originType = RouteOriginType::Warehouse;
        } else {
            $originType = RouteOriginType::None;
        }

        if (RouteOriginType::CourierLocation === $originType) {
            $route->setOriginType(RouteOriginType::CourierLocation);

            return null;
        }

        if (RouteOriginType::None === $originType) {
            $route->setOriginType(RouteOriginType::None);

            return null;
        }

        $warehouseResult = $this->resolveWarehouse($warehouseId);
        if ($warehouseResult instanceof JsonResponse) {
            return $warehouseResult;
        }
        if (null === $warehouseResult) {
            return $this->json(['error' => 'warehouseId is required for warehouse origin'], Response::HTTP_BAD_REQUEST);
        }

        $route->setWarehouse($warehouseResult);

        return null;
    }

    /**
     * @return \App\Entity\Courier|JsonResponse|null
     */
    private function resolveCourier(mixed $courierId): mixed
    {
        if (null === $courierId) {
            return null;
        }

        if (!\is_int($courierId) && !(\is_string($courierId) && ctype_digit($courierId))) {
            return $this->json(['error' => 'courierId must be an integer or null'], Response::HTTP_BAD_REQUEST);
        }

        $courier = $this->couriers->find((int) $courierId);
        if (null === $courier) {
            return $this->json(['error' => 'Courier not found'], Response::HTTP_NOT_FOUND);
        }

        return $courier;
    }

    /**
     * @return \App\Entity\Warehouse|JsonResponse|null
     */
    private function resolveWarehouse(mixed $warehouseId): mixed
    {
        if (null === $warehouseId) {
            return null;
        }

        if (!\is_int($warehouseId) && !(\is_string($warehouseId) && ctype_digit($warehouseId))) {
            return $this->json(['error' => 'warehouseId must be an integer or null'], Response::HTTP_BAD_REQUEST);
        }

        $warehouse = $this->warehouses->find((int) $warehouseId);
        if (null === $warehouse) {
            return $this->json(['error' => 'Warehouse not found'], Response::HTTP_NOT_FOUND);
        }

        return $warehouse;
    }

    #[Route('/{id}', name: 'api_admin_routes_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($route);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
