<?php

namespace App\Service;

use App\Entity\DeliveryRoute;
use Psr\Log\LoggerInterface;

/**
 * Builds the cover-page route overview image for the completion PDF.
 */
class RouteOverviewMapService
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
        private readonly DeliveryRouteMetricsService $metrics,
        private readonly OsmTileMapRenderer $osmTileMap,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return string|null PNG data URI, or null when map cannot be generated
     */
    public function buildDataUri(DeliveryRoute $route): ?string
    {
        $collected = $this->metrics->collectMapPoints($route);
        if (null === $collected || [] === $collected['points']) {
            return null;
        }

        $points = $collected['points'];
        $hasOrigin = $collected['hasOrigin'];

        if ($this->googleMaps->isConfigured()) {
            try {
                $dataUri = $this->googleMaps->buildRouteMapDataUri($points, $hasOrigin);
                if (null !== $dataUri) {
                    return $dataUri;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Google Static Maps failed, trying OSM tiles: '.$e->getMessage(), [
                    'routeId' => $route->getId(),
                ]);
            }
        }

        try {
            $png = $this->osmTileMap->renderPng($points, $hasOrigin);
            if (null === $png) {
                return null;
            }

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable $e) {
            $this->logger?->warning('Route overview map generation failed: '.$e->getMessage(), [
                'routeId' => $route->getId(),
            ]);

            return null;
        }
    }
}
