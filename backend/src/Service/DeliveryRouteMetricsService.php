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
     * Recalculate travel legs using the route origin (warehouse or courier start).
     * First order travel = origin → first stop when an origin point is available.
     */
    public function rebuild(DeliveryRoute $route): void
    {
        $orders = $route->getOrders()->toArray();
        $points = [];
        $originOffset = 0;

        $originPoint = $this->resolveOriginPoint($route);
        if (null !== $originPoint) {
            $points[] = $originPoint;
            $originOffset = 1;
        }

        foreach ($orders as $order) {
            $lat = $order->getLat();
            $lng = $order->getLng();
            if (null === $lat || null === $lng) {
                $this->clearMetrics($route);

                return;
            }

            $points[] = [
                'formattedAddress' => $order->getFormattedAddress() ?? $order->getAddress(),
                'lat' => $lat,
                'lng' => $lng,
                'placeId' => $order->getPlaceId(),
            ];
        }

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
