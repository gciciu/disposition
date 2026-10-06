<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleMapsService
{
    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const DIRECTIONS_URL = 'https://maps.googleapis.com/maps/api/directions/json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @return array{
     *     valid: bool,
     *     input: string,
     *     formattedAddress?: string,
     *     suggestion?: string|null,
     *     partialMatch?: bool,
     *     lat?: float,
     *     lng?: float,
     *     placeId?: string,
     *     error?: string
     * }
     */
    public function geocodeAddress(string $address): array
    {
        $input = trim($address);

        if ('' === $input) {
            return [
                'valid' => false,
                'input' => $input,
                'error' => 'Address is empty',
            ];
        }

        $response = $this->httpClient->request('GET', self::GEOCODE_URL, [
            'query' => [
                'address' => $input,
                'language' => 'de',
                'region' => 'de',
                'key' => $this->apiKey,
            ],
            'timeout' => 10,
        ]);

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'input' => $input,
                'error' => 'Google Maps request failed: '.$e->getMessage(),
            ];
        }

        $status = $data['status'] ?? 'UNKNOWN_ERROR';

        if ('OK' !== $status || empty($data['results'][0])) {
            return [
                'valid' => false,
                'input' => $input,
                'error' => $this->geocodeErrorMessage($status),
            ];
        }

        $result = $data['results'][0];
        $location = $result['geometry']['location'] ?? [];
        $formatted = $result['formatted_address'] ?? $input;
        $partialMatch = !empty($result['partial_match']);
        // Only offer a hint when Google is unsure (partial match). Exact hits that
        // merely reformat the address (e.g. append country) stay silent.
        $suggestion = ($partialMatch && !$this->isSameAddress($input, $formatted))
            ? $formatted
            : null;

        return [
            'valid' => true,
            'input' => $input,
            'formattedAddress' => $formatted,
            'suggestion' => $suggestion,
            'partialMatch' => $partialMatch,
            'lat' => isset($location['lat']) ? (float) $location['lat'] : null,
            'lng' => isset($location['lng']) ? (float) $location['lng'] : null,
            'placeId' => $result['place_id'] ?? null,
        ];
    }

    private function isSameAddress(string $a, string $b): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            $value = str_replace(['ß'], ['ss'], $value);
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            $value = preg_replace('/[^\p{L}\p{N} ]/u', '', $value) ?? $value;

            return $value;
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * @param list<array{formattedAddress: string, lat: float, lng: float, placeId?: string|null}> $points
     *
     * @return array{
     *     segments: list<array<string, mixed>>,
     *     totalDurationSeconds: int,
     *     totalDurationText: string,
     *     totalDistanceMeters: int,
     *     totalDistanceText: string,
     *     mapsUrl: string
     * }
     */
    public function buildTruckRoute(array $points): array
    {
        if (\count($points) < 2) {
            throw new \InvalidArgumentException('At least two valid addresses are required');
        }

        $origin = $this->pointAsLatLng($points[0]);
        $destination = $this->pointAsLatLng($points[\count($points) - 1]);
        $waypoints = [];

        for ($i = 1, $last = \count($points) - 1; $i < $last; ++$i) {
            $waypoints[] = $this->pointAsLatLng($points[$i]);
        }

        $query = [
            'origin' => $origin,
            'destination' => $destination,
            'mode' => 'driving',
            // Prefer major roads suitable for freight; avoids ferries when possible.
            'avoid' => 'ferries',
            'units' => 'metric',
            'language' => 'de',
            'region' => 'de',
            // Align travel time with Google Maps UI (traffic-aware estimate).
            'departure_time' => 'now',
            'traffic_model' => 'best_guess',
            'key' => $this->apiKey,
        ];

        if ($waypoints) {
            $query['waypoints'] = implode('|', $waypoints);
        }

        $response = $this->httpClient->request('GET', self::DIRECTIONS_URL, [
            'query' => $query,
            'timeout' => 20,
        ]);

        $data = $response->toArray(false);
        $status = $data['status'] ?? 'UNKNOWN_ERROR';

        if ('OK' !== $status || empty($data['routes'][0]['legs'])) {
            $detail = isset($data['error_message']) && \is_string($data['error_message']) && '' !== $data['error_message']
                ? $this->directionsErrorMessage($status).' ('.$data['error_message'].')'
                : $this->directionsErrorMessage($status);
            throw new \RuntimeException($detail);
        }

        $legs = $data['routes'][0]['legs'];
        $segments = [];
        $totalDurationSeconds = 0;
        $totalDistanceMeters = 0;

        foreach ($legs as $index => $leg) {
            // Prefer traffic-aware duration when Google returns it (requires departure_time).
            $durationSeconds = (int) ($leg['duration_in_traffic']['value'] ?? $leg['duration']['value'] ?? 0);
            $distanceMeters = (int) ($leg['distance']['value'] ?? 0);
            $totalDurationSeconds += $durationSeconds;
            $totalDistanceMeters += $distanceMeters;

            $segments[] = [
                'fromIndex' => $index,
                'toIndex' => $index + 1,
                'fromAddress' => $leg['start_address'] ?? ($points[$index]['formattedAddress'] ?? ''),
                'toAddress' => $leg['end_address'] ?? ($points[$index + 1]['formattedAddress'] ?? ''),
                'durationSeconds' => $durationSeconds,
                'durationText' => $this->formatDuration($durationSeconds),
                'distanceMeters' => $distanceMeters,
                'distanceText' => $this->formatDistance($distanceMeters),
            ];
        }

        return [
            'segments' => $segments,
            'totalDurationSeconds' => $totalDurationSeconds,
            'totalDurationText' => $this->formatDuration($totalDurationSeconds),
            'totalDistanceMeters' => $totalDistanceMeters,
            'totalDistanceText' => $this->formatDistance($totalDistanceMeters),
            'mapsUrl' => $this->buildGoogleMapsUrl($points),
        ];
    }

    /**
     * Build a Google Maps Directions URL that visits every stop in order.
     *
     * Uses Maps URLs api=1 (with avoid=ferries) when there are ≤9 intermediate
     * waypoints. For longer routes falls back to /dir/A/B/C so no stops are dropped
     * (api=1 silently ignores waypoints beyond the limit).
     *
     * @see https://developers.google.com/maps/documentation/urls/get-started#directions-action
     *
     * @param list<array{formattedAddress?: string, lat?: float|null, lng?: float|null, placeId?: string|null}> $points
     */
    public function buildGoogleMapsUrl(array $points): string
    {
        if ([] === $points) {
            return 'https://www.google.com/maps';
        }

        if (1 === \count($points)) {
            $query = rawurlencode($this->pointAsMapsLocation($points[0]));

            return 'https://www.google.com/maps/search/?api=1&query='.$query;
        }

        $intermediateCount = \count($points) - 2;

        if ($intermediateCount > 9) {
            $parts = [];
            foreach ($points as $point) {
                $parts[] = rawurlencode($this->pointAsMapsLocation($point));
            }

            return 'https://www.google.com/maps/dir/'.implode('/', $parts);
        }

        $origin = $this->pointAsMapsLocation($points[0]);
        $destination = $this->pointAsMapsLocation($points[\count($points) - 1]);
        $waypoints = [];
        $waypointPlaceIds = [];

        for ($i = 1, $last = \count($points) - 1; $i < $last; ++$i) {
            $waypoints[] = $this->pointAsMapsLocation($points[$i]);
            $placeId = $points[$i]['placeId'] ?? null;
            if (\is_string($placeId) && '' !== $placeId) {
                $waypointPlaceIds[] = $placeId;
            }
        }

        $query = [
            'api' => '1',
            'origin' => $origin,
            'destination' => $destination,
            'travelmode' => 'driving',
            'avoid' => 'ferries',
        ];

        $originPlaceId = $points[0]['placeId'] ?? null;
        if (\is_string($originPlaceId) && '' !== $originPlaceId) {
            $query['origin_place_id'] = $originPlaceId;
        }

        $destinationPlaceId = $points[\count($points) - 1]['placeId'] ?? null;
        if (\is_string($destinationPlaceId) && '' !== $destinationPlaceId) {
            $query['destination_place_id'] = $destinationPlaceId;
        }

        if ($waypoints) {
            $query['waypoints'] = implode('|', $waypoints);
            if (\count($waypointPlaceIds) === \count($waypoints)) {
                $query['waypoint_place_ids'] = implode('|', $waypointPlaceIds);
            }
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array{lat: float, lng: float} $point
     */
    private function pointAsLatLng(array $point): string
    {
        return sprintf('%F,%F', $point['lat'], $point['lng']);
    }

    /**
     * Location value for Maps URLs origin/destination/waypoints.
     * Prefer stable lat,lng coordinates; fall back to formatted address.
     *
     * @param array{formattedAddress?: string, lat?: float|null, lng?: float|null, placeId?: string|null} $point
     */
    private function pointAsMapsLocation(array $point): string
    {
        if (isset($point['lat'], $point['lng']) && null !== $point['lat'] && null !== $point['lng']) {
            return sprintf('%F,%F', $point['lat'], $point['lng']);
        }

        return $point['formattedAddress'] ?? '';
    }

    private function geocodeErrorMessage(string $status): string
    {
        return match ($status) {
            'ZERO_RESULTS' => 'Address not found in Google Maps',
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => 'Google Maps quota exceeded',
            'REQUEST_DENIED' => 'Google Maps request denied — check API key',
            'INVALID_REQUEST' => 'Invalid address request',
            default => 'Could not validate address ('.$status.')',
        };
    }

    private function directionsErrorMessage(string $status): string
    {
        return match ($status) {
            'ZERO_RESULTS' => 'No driving route found between the addresses',
            'NOT_FOUND' => 'One or more waypoints could not be geocoded for directions',
            'MAX_WAYPOINTS_EXCEEDED' => 'Too many waypoints for Google Directions',
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => 'Google Maps quota exceeded',
            'REQUEST_DENIED' => 'Google Maps request denied — check API key',
            default => 'Could not build route ('.$status.')',
        };
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }

    private function formatDistance(int $meters): string
    {
        if ($meters >= 1000) {
            return sprintf('%.1f km', $meters / 1000);
        }

        return sprintf('%d m', $meters);
    }
}
