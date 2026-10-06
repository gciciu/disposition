<?php

namespace App\Controller\Api;

use App\Entity\DeliveryRoute;
use App\Exception\RouteAddressValidationException;
use App\Repository\CourierRepository;
use App\Repository\DeliveryRouteRepository;
use App\Service\GoogleMapsService;
use App\Service\RoutePdfImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/routes')]
#[IsGranted('ROLE_ADMIN')]
class DeliveryRouteController extends AbstractController
{
    public function __construct(
        private readonly DeliveryRouteRepository $routes,
        private readonly CourierRepository $couriers,
        private readonly RoutePdfImportService $importer,
        private readonly GoogleMapsService $googleMaps,
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

        $orders = $route->getOrders()->toArray();
        $points = [];
        foreach ($orders as $order) {
            $lat = $order->getLat();
            $lng = $order->getLng();
            if (null === $lat || null === $lng) {
                $points = [];
                break;
            }
            $points[] = [
                'formattedAddress' => $order->getFormattedAddress() ?? $order->getAddress(),
                'lat' => $lat,
                'lng' => $lng,
                'placeId' => $order->getPlaceId(),
            ];
        }

        if (\count($points) >= 2 && $this->googleMaps->isConfigured()) {
            try {
                $built = $this->googleMaps->buildTruckRoute($points);
                $segmentsByToIndex = [];
                foreach ($built['segments'] as $segment) {
                    $segmentsByToIndex[$segment['toIndex']] = $segment;
                }

                foreach ($orders as $index => $order) {
                    $segment = $segmentsByToIndex[$index] ?? null;
                    $order
                        ->setTravelDurationSeconds($segment['durationSeconds'] ?? null)
                        ->setTravelDistanceMeters($segment['distanceMeters'] ?? null);
                }

                $route
                    ->setTotalDurationSeconds($built['totalDurationSeconds'])
                    ->setTotalDistanceMeters($built['totalDistanceMeters'])
                    ->setMapsUrl($built['mapsUrl']);

                $this->em->flush();
            } catch (\Throwable) {
                // Keep stored import values if live recalculation fails.
                if ($points) {
                    $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl($points));
                }
            }
        } elseif ($points) {
            $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl($points));
        }

        return $this->json(['route' => $route->toArray()]);
    }

    #[Route('/{id}/courier', name: 'api_admin_routes_assign_courier', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function assignCourier(int $id, Request $request): JsonResponse
    {
        $route = $this->routes->find($id);
        if (!$route instanceof DeliveryRoute) {
            return $this->json(['error' => 'Route not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        if (!\array_key_exists('courierId', $payload)) {
            return $this->json(['error' => 'courierId is required'], Response::HTTP_BAD_REQUEST);
        }

        $courierId = $payload['courierId'];
        if (null === $courierId) {
            $route->setCourier(null);
            $this->em->flush();

            return $this->json(['route' => $route->toArray()]);
        }

        if (!\is_int($courierId) && !(\is_string($courierId) && ctype_digit($courierId))) {
            return $this->json(['error' => 'courierId must be an integer or null'], Response::HTTP_BAD_REQUEST);
        }

        $courier = $this->couriers->find((int) $courierId);
        if (null === $courier) {
            return $this->json(['error' => 'Courier not found'], Response::HTTP_NOT_FOUND);
        }

        $route->setCourier($courier);
        $this->em->flush();

        return $this->json(['route' => $route->toArray()]);
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
