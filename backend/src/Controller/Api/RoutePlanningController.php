<?php

namespace App\Controller\Api;

use App\Service\GoogleMapsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/route-planning')]
#[IsGranted('ROLE_ADMIN')]
class RoutePlanningController extends AbstractController
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
    ) {
    }

    #[Route('/validate-address', name: 'api_admin_route_planning_validate_address', methods: ['POST'])]
    public function validateAddress(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json([
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        /** @var array{address?: mixed} $payload */
        $payload = $request->toArray();
        $address = isset($payload['address']) && \is_string($payload['address'])
            ? $payload['address']
            : '';

        return $this->json([
            'point' => $this->googleMaps->geocodeAddress($address),
        ]);
    }

    #[Route('/validate-addresses', name: 'api_admin_route_planning_validate_addresses', methods: ['POST'])]
    public function validateAddresses(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json([
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        /** @var array{addresses?: mixed} $payload */
        $payload = $request->toArray();
        $rawAddresses = $payload['addresses'] ?? null;

        if (!\is_array($rawAddresses) || [] === $rawAddresses) {
            return $this->json([
                'error' => 'Provide a non-empty addresses array',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (\count($rawAddresses) > 25) {
            return $this->json([
                'error' => 'Maximum 25 addresses allowed',
            ], Response::HTTP_BAD_REQUEST);
        }

        $points = [];
        foreach ($rawAddresses as $item) {
            if (!\is_string($item)) {
                return $this->json([
                    'error' => 'Each address must be a string',
                ], Response::HTTP_BAD_REQUEST);
            }
            $points[] = $this->googleMaps->geocodeAddress(trim($item));
        }

        return $this->json([
            'points' => $points,
        ]);
    }

    #[Route('/build', name: 'api_admin_route_planning_build', methods: ['POST'])]
    public function build(Request $request): JsonResponse
    {
        if (!$this->googleMaps->isConfigured()) {
            return $this->json([
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        /** @var array{addresses?: mixed} $payload */
        $payload = $request->toArray();
        $rawAddresses = $payload['addresses'] ?? null;

        if (!\is_array($rawAddresses) || [] === $rawAddresses) {
            return $this->json([
                'error' => 'Provide a non-empty addresses array',
            ], Response::HTTP_BAD_REQUEST);
        }

        $addresses = [];
        foreach ($rawAddresses as $item) {
            if (!\is_string($item)) {
                return $this->json([
                    'error' => 'Each address must be a string',
                ], Response::HTTP_BAD_REQUEST);
            }
            $addresses[] = trim($item);
        }

        if (\count($addresses) < 2) {
            return $this->json([
                'error' => 'At least two addresses are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (\count($addresses) > 25) {
            return $this->json([
                'error' => 'Maximum 25 addresses allowed',
            ], Response::HTTP_BAD_REQUEST);
        }

        $points = [];
        $allValid = true;

        foreach ($addresses as $index => $address) {
            $geocoded = $this->googleMaps->geocodeAddress($address);
            $point = array_merge(['index' => $index], $geocoded);
            $points[] = $point;

            if (!$geocoded['valid']) {
                $allValid = false;
            }
        }

        if (!$allValid) {
            return $this->json([
                'ok' => false,
                'points' => $points,
                'segments' => [],
                'mapsUrl' => null,
                'error' => 'One or more addresses were not found in Google Maps',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $routePoints = array_map(static fn (array $point): array => [
            'formattedAddress' => $point['formattedAddress'],
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'placeId' => $point['placeId'] ?? null,
        ], $points);

        try {
            $route = $this->googleMaps->buildTruckRoute($routePoints);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'points' => $points,
                'segments' => [],
                'mapsUrl' => null,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok' => true,
            'points' => $points,
            'segments' => $route['segments'],
            'totalDurationSeconds' => $route['totalDurationSeconds'],
            'totalDurationText' => $route['totalDurationText'],
            'totalDistanceMeters' => $route['totalDistanceMeters'],
            'totalDistanceText' => $route['totalDistanceText'],
            'mapsUrl' => $route['mapsUrl'],
        ]);
    }
}
