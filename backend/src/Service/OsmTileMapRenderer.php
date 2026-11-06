<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders a route overview PNG from OpenStreetMap tiles + path/markers (GD).
 * Used when Google Maps Static API is unavailable for the API key.
 */
class OsmTileMapRenderer
{
    private const TILE_SIZE = 256;
    private const OUTPUT_SIZE = 800;
    private const TILE_URL = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param list<array{lat: float, lng: float, label?: string|null}> $points
     */
    public function renderPng(array $points, bool $firstIsOrigin = false): ?string
    {
        if ([] === $points || !\function_exists('imagecreatetruecolor')) {
            return null;
        }

        $lats = array_map(static fn (array $p): float => (float) $p['lat'], $points);
        $lngs = array_map(static fn (array $p): float => (float) $p['lng'], $points);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        // Padding so markers are not on the edge.
        $latPad = max(0.02, ($maxLat - $minLat) * 0.12);
        $lngPad = max(0.02, ($maxLng - $minLng) * 0.12);
        $minLat -= $latPad;
        $maxLat += $latPad;
        $minLng -= $lngPad;
        $maxLng += $lngPad;

        $zoom = $this->bestZoom($minLat, $maxLat, $minLng, $maxLng, self::OUTPUT_SIZE);
        $nw = $this->latLngToWorldPixel($maxLat, $minLng, $zoom);
        $se = $this->latLngToWorldPixel($minLat, $maxLng, $zoom);

        $worldW = max(1.0, $se['x'] - $nw['x']);
        $worldH = max(1.0, $se['y'] - $nw['y']);
        $scale = min(self::OUTPUT_SIZE / $worldW, self::OUTPUT_SIZE / $worldH);
        $outW = (int) max(1, round($worldW * $scale));
        $outH = (int) max(1, round($worldH * $scale));

        $canvas = imagecreatetruecolor($outW, $outH);
        if (false === $canvas) {
            return null;
        }
        $bg = imagecolorallocate($canvas, 236, 239, 241);
        imagefilledrectangle($canvas, 0, 0, $outW, $outH, $bg);

        $tileMinX = (int) floor($nw['x'] / self::TILE_SIZE);
        $tileMaxX = (int) floor(($se['x'] - 0.001) / self::TILE_SIZE);
        $tileMinY = (int) floor($nw['y'] / self::TILE_SIZE);
        $tileMaxY = (int) floor(($se['y'] - 0.001) / self::TILE_SIZE);
        $n = 2 ** $zoom;

        for ($ty = $tileMinY; $ty <= $tileMaxY; ++$ty) {
            for ($tx = $tileMinX; $tx <= $tileMaxX; ++$tx) {
                if ($ty < 0 || $ty >= $n) {
                    continue;
                }
                $wrappedX = (($tx % $n) + $n) % $n;
                $tile = $this->fetchTile($zoom, $wrappedX, $ty);
                if (null === $tile) {
                    continue;
                }

                $destX = (int) round(($tx * self::TILE_SIZE - $nw['x']) * $scale);
                $destY = (int) round(($ty * self::TILE_SIZE - $nw['y']) * $scale);
                $destW = (int) max(1, round(self::TILE_SIZE * $scale));
                $destH = (int) max(1, round(self::TILE_SIZE * $scale));
                imagecopyresampled(
                    $canvas,
                    $tile,
                    $destX,
                    $destY,
                    0,
                    0,
                    $destW,
                    $destH,
                    self::TILE_SIZE,
                    self::TILE_SIZE,
                );
                imagedestroy($tile);
            }
        }

        $pixelPoints = [];
        foreach ($points as $point) {
            $world = $this->latLngToWorldPixel((float) $point['lat'], (float) $point['lng'], $zoom);
            $pixelPoints[] = [
                'x' => (int) round(($world['x'] - $nw['x']) * $scale),
                'y' => (int) round(($world['y'] - $nw['y']) * $scale),
                'label' => $point['label'] ?? null,
            ];
        }

        $pathColor = imagecolorallocatealpha($canvas, 26, 115, 232, 40);
        imagesetthickness($canvas, 3);
        for ($i = 1, $c = \count($pixelPoints); $i < $c; ++$i) {
            imageline(
                $canvas,
                $pixelPoints[$i - 1]['x'],
                $pixelPoints[$i - 1]['y'],
                $pixelPoints[$i]['x'],
                $pixelPoints[$i]['y'],
                $pathColor,
            );
        }
        imagesetthickness($canvas, 1);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $blue = imagecolorallocate($canvas, 66, 133, 244);
        $green = imagecolorallocate($canvas, 52, 168, 83);
        $black = imagecolorallocate($canvas, 20, 20, 20);

        foreach ($pixelPoints as $index => $pt) {
            $isOrigin = $firstIsOrigin && 0 === $index;
            $fill = $isOrigin ? $green : $blue;
            $label = $isOrigin ? 'S' : (string) ($pt['label'] ?? ($firstIsOrigin ? (string) $index : (string) ($index + 1)));
            $font = \strlen($label) >= 2 ? 3 : 4;
            $r = \strlen($label) >= 2 ? 12 : 10;
            imagefilledellipse($canvas, $pt['x'], $pt['y'], $r * 2, $r * 2, $fill);
            imageellipse($canvas, $pt['x'], $pt['y'], $r * 2, $r * 2, $white);

            $tw = imagefontwidth($font) * \strlen($label);
            $th = imagefontheight($font);
            imagestring($canvas, $font, (int) ($pt['x'] - $tw / 2), (int) ($pt['y'] - $th / 2), $label, $white);
        }

        // Tiny attribution so OSM tile use stays polite.
        imagestring($canvas, 1, 6, $outH - 12, '© OpenStreetMap', $black);

        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();
        imagedestroy($canvas);

        return false === $png || '' === $png ? null : $png;
    }

    private function fetchTile(int $zoom, int $x, int $y): mixed
    {
        try {
            $response = $this->httpClient->request('GET', sprintf(self::TILE_URL, $zoom, $x, $y), [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'DeliveryRoutePdf/1.0 (route overview; local compose)',
                ],
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }
            $binary = $response->getContent(false);
            $tile = @imagecreatefromstring($binary);

            return false === $tile ? null : $tile;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{x: float, y: float}
     */
    private function latLngToWorldPixel(float $lat, float $lng, int $zoom): array
    {
        $lat = max(min($lat, 85.05112878), -85.05112878);
        $n = 2 ** $zoom;
        $x = ($lng + 180.0) / 360.0 * $n * self::TILE_SIZE;
        $latRad = deg2rad($lat);
        $y = (1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n * self::TILE_SIZE;

        return ['x' => $x, 'y' => $y];
    }

    private function bestZoom(float $minLat, float $maxLat, float $minLng, float $maxLng, int $size): int
    {
        for ($zoom = 14; $zoom >= 5; --$zoom) {
            $nw = $this->latLngToWorldPixel($maxLat, $minLng, $zoom);
            $se = $this->latLngToWorldPixel($minLat, $maxLng, $zoom);
            $w = $se['x'] - $nw['x'];
            $h = $se['y'] - $nw['y'];
            if ($w <= $size * 1.15 && $h <= $size * 1.15) {
                return $zoom;
            }
        }

        return 5;
    }
}
