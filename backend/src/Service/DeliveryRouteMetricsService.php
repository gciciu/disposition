<?php

namespace App\Service;

use App\Entity\DeliveryRoute;
use App\Entity\Warehouse;
use App\Enum\RouteOriginType;

class DeliveryRouteMetricsService
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
    ) {
    }

    /**
     * Ordered map points for the route: optional origin + each stop with coordinates.
     * Returns null when any stop is missing lat/lng.
     *
     * Origin (warehouse / courier GPS) is omitted when it is unrealistically far from
     * the stops (e.g. a bogus device location on another continent) so the overview
     * map and Directions stay usable.
     *
     * @return array{
     *     points: list<array{formattedAddress: string, lat: float, lng: float, placeId: string|null, label?: string}>,
     *     hasOrigin: bool
     * }|null
     */
    public function collectMapPoints(DeliveryRoute $route): ?array
    {
        $orders = $route->getOrders()->toArray();
        $stopPoints = [];

        foreach ($orders as $order) {
            $lat = $order->getLat();
            $lng = $order->getLng();
            if (null === $lat || null === $lng) {
                return null;
            }

            $stopPoints[] = [
                'formattedAddress' => $order->getFormattedAddress() ?? $order->getAddress(),
                'lat' => $lat,
                'lng' => $lng,
                'placeId' => $order->getPlaceId(),
                'label' => $this->googleMapsLabelForPosition($order->getPosition()),
            ];
        }

        $originPoint = $this->resolveOriginPoint($route);
        $hasOrigin = null !== $originPoint && $this->isOriginNearStops($originPoint, $stopPoints);

        $points = $hasOrigin ? array_merge([$originPoint], $stopPoints) : $stopPoints;

        return [
            'points' => $points,
            'hasOrigin' => $hasOrigin,
        ];
    }

    /**
     * @param array{lat: float, lng: float}                                                            $origin
     * @param list<array{lat: float, lng: float}>                                                      $stops
     */
    private function isOriginNearStops(array $origin, array $stops): bool
    {
        if ([] === $stops) {
            return true;
        }

        $nearestKm = null;
        foreach ($stops as $stop) {
            $km = $this->haversineKm(
                (float) $origin['lat'],
                (float) $origin['lng'],
                (float) $stop['lat'],
                (float) $stop['lng'],
            );
            $nearestKm = null === $nearestKm ? $km : min($nearestKm, $km);
        }

        // Drop GPS glitches / wrong-hemisphere starts (route stops are regional).
        return null !== $nearestKm && $nearestKm <= 500.0;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Recalculate travel legs using the route origin (warehouse or courier start).
     * First order travel = origin → first stop when an origin point is available.
     */
    public function rebuild(DeliveryRoute $route): void
    {
        $orders = $route->getOrders()->toArray();
        $collected = $this->collectMapPoints($route);
        if (null === $collected) {
            $this->clearMetrics($route);

            return;
        }

        $points = $collected['points'];
        $originOffset = $collected['hasOrigin'] ? 1 : 0;

        if (\count($points) < 2) {
            if ($points) {
                $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl($points));
            } else {
                $route->setMapsUrl(null);
            }
            $route->setTotalDurationSeconds(null);
            $route->setTotalDistanceMeters(null);
            foreach ($orders as $order) {
                $order->setTravelDurationSeconds(null)->setTravelDistanceMeters(null);
            }

            return;
        }

        if (!$this->googleMaps->isConfigured()) {
            $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl($points));

            return;
        }

        try {
            $built = $this->googleMaps->buildTruckRoute($points);
        } catch (\Throwable) {
            $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl($points));

            return;
        }

        $segmentsByToIndex = [];
        foreach ($built['segments'] as $segment) {
            $segmentsByToIndex[$segment['toIndex']] = $segment;
        }

        foreach ($orders as $index => $order) {
            $segment = $segmentsByToIndex[$index + $originOffset] ?? null;
            $order
                ->setTravelDurationSeconds($segment['durationSeconds'] ?? null)
                ->setTravelDistanceMeters($segment['distanceMeters'] ?? null);
        }

        $route
            ->setTotalDurationSeconds($built['totalDurationSeconds'])
            ->setTotalDistanceMeters($built['totalDistanceMeters'])
            ->setMapsUrl($built['mapsUrl']);
    }

    private function clearMetrics(DeliveryRoute $route): void
    {
        $route
            ->setTotalDurationSeconds(null)
            ->setTotalDistanceMeters(null)
            ->setMapsUrl(null);

        foreach ($route->getOrders() as $order) {
            $order->setTravelDurationSeconds(null)->setTravelDistanceMeters(null);
        }
    }

    /** Route-sheet position number drawn on the overview map. */
    private function googleMapsLabelForPosition(int $position): string
    {
        return (string) max(1, $position);
    }

    /**
     * @return array{formattedAddress: string, lat: float, lng: float, placeId: string|null}|null
     */
    private function resolveOriginPoint(DeliveryRoute $route): ?array
    {
        return match ($route->getOriginType()) {
            RouteOriginType::Warehouse => $this->warehouseAsPoint($route->getWarehouse()),
            RouteOriginType::CourierLocation => $this->courierStartAsPoint($route),
            RouteOriginType::None => null,
        };
    }

    /**
     * @return array{formattedAddress: string, lat: float, lng: float, placeId: string|null}|null
     */
    private function courierStartAsPoint(DeliveryRoute $route): ?array
    {
        $lat = $route->getStartLat();
        $lng = $route->getStartLng();
        if (null === $lat || null === $lng) {
            return null;
        }

        return [
            'formattedAddress' => sprintf('%.6f, %.6f', $lat, $lng),
            'lat' => $lat,
            'lng' => $lng,
            'placeId' => null,
        ];
    }

    /**
     * @return array{formattedAddress: string, lat: float, lng: float, placeId: string|null}|null
     */
    private function warehouseAsPoint(?Warehouse $warehouse): ?array
    {
        if (null === $warehouse) {
            return null;
        }

        $lat = $warehouse->getLat();
        $lng = $warehouse->getLng();
        if (null === $lat || null === $lng) {
            return null;
        }

        return [
            'formattedAddress' => $warehouse->getFormattedAddress() ?? $warehouse->getAddress(),
            'lat' => $lat,
            'lng' => $lng,
            'placeId' => $warehouse->getPlaceId(),
        ];
    }
}
