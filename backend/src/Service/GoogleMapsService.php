<?php

namespace App\Service;

use App\Util\AddressFormatter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleMapsService
{
    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const DIRECTIONS_URL = 'https://maps.googleapis.com/maps/api/directions/json';
    private const STATIC_MAP_URL = 'https://maps.googleapis.com/maps/api/staticmap';
    private const AUTOCOMPLETE_URL = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
    private const PLACE_DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

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
     * @return list<array{placeId: string, description: string, mainText: string, secondaryText: string}>
     */
    public function autocompleteAddress(string $input): array
    {
        $input = trim($input);
        if ('' === $input) {
            return [];
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException('GOOGLE_MAPS_API_KEY is not configured');
        }

        $response = $this->httpClient->request('GET', self::AUTOCOMPLETE_URL, [
            'query' => [
                'input' => $input,
                'language' => 'de',
                'components' => 'country:de',
                'types' => 'address',
                'key' => $this->apiKey,
            ],
            'timeout' => 10,
        ]);

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Google Maps request failed: '.$e->getMessage(), 0, $e);
        }

        $status = $data['status'] ?? 'UNKNOWN_ERROR';
        if ('ZERO_RESULTS' === $status) {
            return [];
        }

        if ('OK' !== $status) {
            throw new \RuntimeException($this->placesErrorMessage($status));
        }

        $predictions = [];
        foreach ($data['predictions'] ?? [] as $prediction) {
            $placeId = $prediction['place_id'] ?? null;
            $description = $prediction['description'] ?? null;
            if (!\is_string($placeId) || '' === $placeId || !\is_string($description) || '' === $description) {
                continue;
            }

            $structured = $prediction['structured_formatting'] ?? [];
            $mainText = \is_string($structured['main_text'] ?? null) ? $structured['main_text'] : $description;
            $secondaryText = \is_string($structured['secondary_text'] ?? null) ? $structured['secondary_text'] : '';
            $predictions[] = [
                'placeId' => $placeId,
                'description' => AddressFormatter::withoutCountry($description) ?? $description,
                'mainText' => $mainText,
                'secondaryText' => AddressFormatter::withoutCountry($secondaryText) ?? $secondaryText,
            ];
        }

        return $predictions;
    }

    /**
     * @return array{
     *     valid: bool,
     *     formattedAddress?: string,
     *     lat?: float,
     *     lng?: float,
     *     placeId?: string,
     *     error?: string
     * }
     */
    public function getPlaceDetails(string $placeId): array
    {
        $placeId = trim($placeId);
        if ('' === $placeId) {
            return [
                'valid' => false,
                'error' => 'Place id is empty',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'valid' => false,
                'error' => 'GOOGLE_MAPS_API_KEY is not configured',
            ];
        }

        $response = $this->httpClient->request('GET', self::PLACE_DETAILS_URL, [
            'query' => [
                'place_id' => $placeId,
                'fields' => 'formatted_address,geometry,place_id',
                'language' => 'de',
                'key' => $this->apiKey,
            ],
            'timeout' => 10,
        ]);

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => 'Google Maps request failed: '.$e->getMessage(),
            ];
        }

        $status = $data['status'] ?? 'UNKNOWN_ERROR';
        $result = $data['result'] ?? null;
        if ('OK' !== $status || !\is_array($result)) {
            return [
                'valid' => false,
                'error' => $this->placesErrorMessage($status),
            ];
        }

        $location = $result['geometry']['location'] ?? [];

        $formatted = $result['formatted_address'] ?? null;

        return [
            'valid' => true,
            'formattedAddress' => \is_string($formatted)
                ? (AddressFormatter::withoutCountry($formatted) ?? $formatted)
                : null,
            'lat' => isset($location['lat']) ? (float) $location['lat'] : null,
            'lng' => isset($location['lng']) ? (float) $location['lng'] : null,
            'placeId' => $result['place_id'] ?? $placeId,
        ];
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
        $formatted = AddressFormatter::withoutCountry($result['formatted_address'] ?? $input) ?? $input;
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
            $value = AddressFormatter::withoutCountry($value) ?? $value;
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
     *     mapsUrl: string,
     *     overviewPolyline: string|null
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

            $fromAddress = $leg['start_address'] ?? ($points[$index]['formattedAddress'] ?? '');
            $toAddress = $leg['end_address'] ?? ($points[$index + 1]['formattedAddress'] ?? '');
            $segments[] = [
                'fromIndex' => $index,
                'toIndex' => $index + 1,
                'fromAddress' => AddressFormatter::withoutCountry($fromAddress) ?? $fromAddress,
                'toAddress' => AddressFormatter::withoutCountry($toAddress) ?? $toAddress,
                'durationSeconds' => $durationSeconds,
                'durationText' => $this->formatDuration($durationSeconds),
                'distanceMeters' => $distanceMeters,
                'distanceText' => $this->formatDistance($distanceMeters),
            ];
        }

        $overviewPolyline = $data['routes'][0]['overview_polyline']['points'] ?? null;
        if (!\is_string($overviewPolyline) || '' === $overviewPolyline) {
            $overviewPolyline = null;
        }

        return [
            'segments' => $segments,
            'totalDurationSeconds' => $totalDurationSeconds,
            'totalDurationText' => $this->formatDuration($totalDurationSeconds),
            'totalDistanceMeters' => $totalDistanceMeters,
            'totalDistanceText' => $this->formatDistance($totalDistanceMeters),
            'mapsUrl' => $this->buildGoogleMapsUrl($points),
            'overviewPolyline' => $overviewPolyline,
        ];
    }

    /**
     * Build a PNG data URI of the route overview (Google Static Maps).
     *
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     * @param bool                                                     $firstIsOrigin When true, first point is warehouse/start (green "S")
     */
    public function buildRouteMapDataUri(array $points, bool $firstIsOrigin = false): ?string
    {
        if ([] === $points) {
            return null;
        }

        $png = $this->fetchRouteMapPng($points, $firstIsOrigin);
        if (null === $png) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     */
    public function fetchRouteMapPng(array $points, bool $firstIsOrigin = false): ?string
    {
        if ([] === $points) {
            return null;
        }

        $polyline = null;
        if ($this->isConfigured() && \count($points) >= 2) {
            try {
                $polyline = $this->buildTruckRoute($points)['overviewPolyline'] ?? null;
            } catch (\Throwable) {
                $polyline = null;
            }
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $lastError = null;
        try {
            return $this->requestStaticMapPng($points, $polyline, $firstIsOrigin);
        } catch (\Throwable $e) {
            $lastError = $e;
        }

        // Retry without encoded polyline (straight segments) when Directions path was used.
        if (null !== $polyline) {
            try {
                return $this->requestStaticMapPng($points, null, $firstIsOrigin);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        // Surface key/API misconfiguration instead of a silent null (caller may fall back).
        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }

        return null;
    }

    /**
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     */
    private function requestStaticMapPng(array $points, ?string $encodedPolyline, bool $firstIsOrigin): string
    {
        $viewport = $this->computeStaticMapViewport($points);
        $scale = 2;

        $query = [
            'size' => $viewport['width'].'x'.$viewport['height'],
            'scale' => (string) $scale,
            'center' => sprintf('%F,%F', $viewport['centerLat'], $viewport['centerLng']),
            'zoom' => (string) $viewport['zoom'],
            'maptype' => 'roadmap',
            'format' => 'png',
            'language' => 'de',
            'region' => 'de',
            'key' => $this->apiKey,
        ];

        if (null !== $encodedPolyline && '' !== $encodedPolyline) {
            $query['path'] = 'weight:4|color:0x1a73e8ff|enc:'.$encodedPolyline;
        } elseif (\count($points) >= 2) {
            $coords = [];
            foreach ($points as $point) {
                $coords[] = sprintf('%F,%F', $point['lat'], $point['lng']);
            }
            $query['path'] = 'weight:4|color:0x1a73e8ff|'.implode('|', $coords);
        }

        // Unlabeled markers — Static Maps only allows a single-char label; we stamp
        // full route-sheet numbers afterwards with GD.
        $markerParams = $this->buildStaticMapMarkers($points, $firstIsOrigin);

        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }
        foreach ($markerParams as $marker) {
            $parts[] = 'markers='.rawurlencode($marker);
        }

        $url = self::STATIC_MAP_URL.'?'.implode('&', $parts);
        if (\strlen($url) > 8190) {
            $parts = [];
            foreach ($query as $key => $value) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
            foreach ($this->buildStaticMapMarkers($points, $firstIsOrigin, true) as $marker) {
                $parts[] = 'markers='.rawurlencode($marker);
            }
            $url = self::STATIC_MAP_URL.'?'.implode('&', $parts);
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        $binary = $response->getContent(false);
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

        if ($statusCode >= 400 || !str_starts_with($contentType, 'image/') || '' === $binary) {
            $hint = trim(substr(strip_tags($binary), 0, 240));
            throw new \RuntimeException(
                'Google Static Maps request failed (HTTP '.$statusCode.')'
                .('' !== $hint ? ': '.$hint : '')
                .'. Enable “Maps Static API” for this key in Google Cloud Console.',
            );
        }

        if (\strlen($binary) < 800) {
            throw new \RuntimeException('Google Static Maps returned an empty/error image');
        }

        return $this->stampRouteLabelsOnMap(
            $binary,
            $points,
            $firstIsOrigin,
            $viewport['centerLat'],
            $viewport['centerLng'],
            $viewport['zoom'],
            $viewport['width'],
            $viewport['height'],
            $scale,
        );
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     *
     * @return array{centerLat: float, centerLng: float, zoom: int, width: int, height: int}
     */
    private function computeStaticMapViewport(array $points): array
    {
        $lats = array_map(static fn (array $p): float => (float) $p['lat'], $points);
        $lngs = array_map(static fn (array $p): float => (float) $p['lng'], $points);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        $latPad = max(0.015, ($maxLat - $minLat) * 0.14);
        $lngPad = max(0.015, ($maxLng - $minLng) * 0.14);
        $minLat -= $latPad;
        $maxLat += $latPad;
        $minLng -= $lngPad;
        $maxLng += $lngPad;

        $centerLat = ($minLat + $maxLat) / 2;
        $centerLng = ($minLng + $maxLng) / 2;

        // Keep geographic aspect ratio (no horizontal stretch). Max edge 640 (Static Maps free limit).
        $nw = $this->latLngToWorldPixel($maxLat, $minLng, 0);
        $se = $this->latLngToWorldPixel($minLat, $maxLng, 0);
        $aspect = max(0.55, min(1.8, ($se['x'] - $nw['x']) / max(1.0, $se['y'] - $nw['y'])));

        if ($aspect >= 1.0) {
            $width = 640;
            $height = (int) max(360, min(640, round(640 / $aspect)));
        } else {
            $height = 640;
            $width = (int) max(360, min(640, round(640 * $aspect)));
        }

        $zoom = 14;
        for (; $zoom >= 5; --$zoom) {
            if ($this->pointsFitViewport($points, $centerLat, $centerLng, $zoom, $width, $height, 24)) {
                break;
            }
        }

        return [
            'centerLat' => $centerLat,
            'centerLng' => $centerLng,
            'zoom' => max(5, $zoom),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     */
    private function pointsFitViewport(
        array $points,
        float $centerLat,
        float $centerLng,
        int $zoom,
        int $width,
        int $height,
        int $paddingPx,
    ): bool {
        foreach ($points as $point) {
            $px = $this->latLngToStaticMapPixel(
                (float) $point['lat'],
                (float) $point['lng'],
                $centerLat,
                $centerLng,
                $zoom,
                $width,
                $height,
                1,
            );
            if (
                $px['x'] < $paddingPx
                || $px['y'] < $paddingPx
                || $px['x'] > $width - $paddingPx
                || $px['y'] > $height - $paddingPx
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     *
     * @return list<string>
     */
    private function buildStaticMapMarkers(array $points, bool $firstIsOrigin, bool $startEndOnly = false): array
    {
        $markers = [];
        $lastIndex = \count($points) - 1;

        foreach ($points as $index => $point) {
            $coord = sprintf('%F,%F', $point['lat'], $point['lng']);

            if ($startEndOnly) {
                if (0 === $index) {
                    $markers[] = 'color:0x34a853|size:mid|'.$coord;
                } elseif ($index === $lastIndex) {
                    $markers[] = 'color:0xea4335|size:mid|'.$coord;
                }
                continue;
            }

            if ($firstIsOrigin && 0 === $index) {
                $markers[] = 'color:0x34a853|size:mid|'.$coord;
                continue;
            }

            $markers[] = 'color:0x4285f4|size:mid|'.$coord;
        }

        return $markers;
    }

    /**
     * Draw full route-sheet position numbers on the Static Maps image.
     *
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     */
    private function stampRouteLabelsOnMap(
        string $pngBinary,
        array $points,
        bool $firstIsOrigin,
        float $centerLat,
        float $centerLng,
        int $zoom,
        int $width,
        int $height,
        int $scale,
    ): string {
        if (!\function_exists('imagecreatefromstring')) {
            return $pngBinary;
        }

        $image = @imagecreatefromstring($pngBinary);
        if (false === $image) {
            return $pngBinary;
        }

        imagesavealpha($image, true);
        $white = imagecolorallocate($image, 255, 255, 255);
        $shadow = imagecolorallocate($image, 20, 20, 20);

        foreach ($points as $index => $point) {
            $isOrigin = $firstIsOrigin && 0 === $index;
            $label = $isOrigin
                ? 'S'
                : (string) ($point['label'] ?? ($firstIsOrigin ? (string) $index : (string) ($index + 1)));

            $px = $this->latLngToStaticMapPixel(
                (float) $point['lat'],
                (float) $point['lng'],
                $centerLat,
                $centerLng,
                $zoom,
                $width,
                $height,
                $scale,
            );

            $font = \strlen($label) >= 2 ? 3 : 4;
            $tw = imagefontwidth($font) * \strlen($label);
            $th = imagefontheight($font);
            $x = (int) round($px['x'] - $tw / 2);
            $y = (int) round($px['y'] - $th / 2);
            imagestring($image, $font, $x + 1, $y + 1, $label, $shadow);
            imagestring($image, $font, $x, $y, $label, $white);
        }

        ob_start();
        imagepng($image, null, 6);
        $out = ob_get_clean();
        imagedestroy($image);

        return false === $out || '' === $out ? $pngBinary : $out;
    }

    /**
     * @return array{x: float, y: float}
     */
    private function latLngToStaticMapPixel(
        float $lat,
        float $lng,
        float $centerLat,
        float $centerLng,
        int $zoom,
        int $width,
        int $height,
        int $scale,
    ): array {
        $point = $this->latLngToWorldPixel($lat, $lng, $zoom);
        $center = $this->latLngToWorldPixel($centerLat, $centerLng, $zoom);

        return [
            'x' => ($point['x'] - $center['x']) * $scale + ($width * $scale) / 2,
            'y' => ($point['y'] - $center['y']) * $scale + ($height * $scale) / 2,
        ];
    }

    /**
     * @return array{x: float, y: float}
     */
    private function latLngToWorldPixel(float $lat, float $lng, int $zoom): array
    {
        $lat = max(min($lat, 85.05112878), -85.05112878);
        $n = 2 ** $zoom;
        $tileSize = 256;
        $x = ($lng + 180.0) / 360.0 * $n * $tileSize;
        $latRad = deg2rad($lat);
        $y = (1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n * $tileSize;

        return ['x' => $x, 'y' => $y];
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

    private function placesErrorMessage(string $status): string
    {
        return match ($status) {
            'ZERO_RESULTS' => 'Address not found in Google Maps',
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => 'Google Maps quota exceeded',
            'REQUEST_DENIED' => 'Google Maps request denied — check API key and enable Places API',
            'INVALID_REQUEST' => 'Invalid places request',
            'NOT_FOUND' => 'Place not found in Google Maps',
            default => 'Could not look up address ('.$status.')',
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
