<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Exception\RouteAddressValidationException;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RoutePdfImportService
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
    ) {
    }

    public function import(UploadedFile $file): DeliveryRoute
    {
        if (!$this->googleMaps->isConfigured()) {
            throw new \RuntimeException('GOOGLE_MAPS_API_KEY is not configured');
        }

        $text = $this->extractText($file);
        $parsed = $this->parseFirstTable($text);

        if ([] === $parsed['orders']) {
            throw new \InvalidArgumentException('No orders found in the first table of the PDF');
        }

        $geocodedOrders = [];
        $invalidAddresses = [];

        foreach ($parsed['orders'] as $orderData) {
            $geocode = $this->googleMaps->geocodeAddress($orderData['address']);
            if (!$geocode['valid']) {
                $invalidAddresses[] = [
                    'position' => $orderData['position'],
                    'clientName' => $orderData['clientName'],
                    'address' => $orderData['address'],
                    'error' => $geocode['error'] ?? 'Address not found in Google Maps',
                ];
                continue;
            }

            $geocodedOrders[] = [
                ...$orderData,
                'formattedAddress' => $geocode['formattedAddress'] ?? $orderData['address'],
                'lat' => $geocode['lat'] ?? null,
                'lng' => $geocode['lng'] ?? null,
                'placeId' => $geocode['placeId'] ?? null,
            ];
        }

        if ([] !== $invalidAddresses) {
            throw new RouteAddressValidationException($invalidAddresses);
        }

        $route = (new DeliveryRoute())
            ->setName($parsed['routeName'])
            ->setSourceFilename($file->getClientOriginalName());

        $segmentsByToIndex = [];
        if (\count($geocodedOrders) >= 2) {
            $routePoints = array_map(static fn (array $orderData): array => [
                'formattedAddress' => $orderData['formattedAddress'],
                'lat' => $orderData['lat'],
                'lng' => $orderData['lng'],
                'placeId' => $orderData['placeId'],
            ], $geocodedOrders);

            $built = $this->googleMaps->buildTruckRoute($routePoints);
            foreach ($built['segments'] as $segment) {
                $segmentsByToIndex[$segment['toIndex']] = $segment;
            }

            $route
                ->setTotalDurationSeconds($built['totalDurationSeconds'])
                ->setTotalDistanceMeters($built['totalDistanceMeters'])
                ->setMapsUrl($built['mapsUrl']);
        } elseif (1 === \count($geocodedOrders)) {
            $route->setMapsUrl($this->googleMaps->buildGoogleMapsUrl([[
                'formattedAddress' => $geocodedOrders[0]['formattedAddress'],
                'lat' => $geocodedOrders[0]['lat'],
                'lng' => $geocodedOrders[0]['lng'],
                'placeId' => $geocodedOrders[0]['placeId'],
            ]]));
        }

        foreach ($geocodedOrders as $index => $orderData) {
            $segment = $segmentsByToIndex[$index] ?? null;
            $order = (new DeliveryOrder())
                ->setPosition($orderData['position'])
                ->setClientName($orderData['clientName'])
                ->setPhone($orderData['phone'])
                ->setAddress($orderData['address'])
                ->setAddressValid(true)
                ->setFormattedAddress($orderData['formattedAddress'])
                ->setLat($orderData['lat'])
                ->setLng($orderData['lng'])
                ->setPlaceId($orderData['placeId'])
                ->setTravelDurationSeconds($segment['durationSeconds'] ?? null)
                ->setTravelDistanceMeters($segment['distanceMeters'] ?? null)
                ->setProducts($orderData['products']);

            $route->addOrder($order);
        }

        return $route;
    }

    private function extractText(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (false === $path || !is_readable($path)) {
            throw new \InvalidArgumentException('Uploaded PDF is not readable');
        }

        try {
            $pdf = (new Parser())->parseFile($path);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Could not read PDF: '.$e->getMessage(), 0, $e);
        }

        $text = trim($text);
        if ('' === $text) {
            throw new \InvalidArgumentException('PDF contains no extractable text');
        }

        return $text;
    }

    /**
     * @return array{
     *     routeName: string,
     *     orders: list<array{
     *         position: int,
     *         clientName: string,
     *         phone: string|null,
     *         address: string,
     *         products: list<string>
     *     }>
     * }
     */
    public function parseFirstTable(string $text): array
    {
        $firstTable = $this->cutToFirstTable($text);
        $routeName = $this->extractRouteName($firstTable) ?? 'Imported route';
        $lines = preg_split('/\R/u', $firstTable) ?: [];

        $orders = [];
        $current = null;
        $section = 'idle'; // idle|name|address|contact|lp|products|tail

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ('' === $line) {
                continue;
            }

            if ($this->isSkippableHeader($line)) {
                continue;
            }

            if ($this->isPositionLine($line, $section) && preg_match('/^(\d{1,3})(?:\s+(.+))?$/u', $line, $m)) {
                if (null !== $current) {
                    $orders[] = $this->finalizeOrder($current);
                }

                $current = [
                    'position' => (int) $m[1],
                    'clientName' => isset($m[2]) ? trim($m[2]) : '',
                    'phone' => null,
                    'address' => '',
                    'products' => [],
                    'nameParts' => isset($m[2]) && '' !== trim($m[2]) ? [trim($m[2])] : [],
                    'addressParts' => [],
                    'contactParts' => [],
                    'productBuffer' => null,
                ];
                $section = 'name';
                continue;
            }

            if (null === $current) {
                continue;
            }

            if ('name' === $section) {
                if ($this->isAddressStart($line)) {
                    $current['addressParts'][] = $line;
                    $section = 'address';
                    continue;
                }
                $current['nameParts'][] = $line;
                continue;
            }

            if ('address' === $section) {
                if ($this->isContactLine($line)) {
                    $current['contactParts'][] = $line;
                    $section = 'contact';
                    continue;
                }
                $current['addressParts'][] = $line;
                continue;
            }

            if ('contact' === $section) {
                if ($this->isLpLine($line)) {
                    $this->applyLpLine($current, $line);
                    $section = 'products';
                    continue;
                }
                if ($this->isContactLine($line) || $this->looksLikeEmail($line)) {
                    $current['contactParts'][] = $line;
                    continue;
                }
                // Unexpected content after contact — treat as LP/products fallback.
                if ($this->isProductStart($line)) {
                    $this->pushProduct($current, $line);
                    $section = 'products';
                    continue;
                }
                $current['contactParts'][] = $line;
                continue;
            }

            if ('products' === $section || 'tail' === $section) {
                if ($this->isWeightLine($line) || $this->isRemarkLine($line)) {
                    $this->flushProductBuffer($current);
                    $section = 'tail';
                    continue;
                }

                if ($this->isProductStart($line)) {
                    $this->pushProduct($current, $line);
                    $section = 'products';
                    continue;
                }

                if ('products' === $section && null !== $current['productBuffer']) {
                    $current['productBuffer'] .= ' '.$line;
                    continue;
                }
            }
        }

        if (null !== $current) {
            $orders[] = $this->finalizeOrder($current);
        }

        return [
            'routeName' => $routeName,
            'orders' => $orders,
        ];
    }

    private function cutToFirstTable(string $text): string
    {
        $parts = preg_split('/^\s*Zubehör\b.*$/um', $text, 2);

        return trim($parts[0] ?? $text);
    }

    private function extractRouteName(string $text): ?string
    {
        if (preg_match('/Seite\s+\d+\s*\/\s*\d+\s+(.+?)(?:\r?\n|$)/u', $text, $m)) {
            $name = trim($m[1]);
            $name = preg_replace('/\s+\(\d+[.,]\d+\s*kg\)\s*$/iu', '', $name) ?? $name;

            return trim($name);
        }

        return null;
    }

    private function isSkippableHeader(string $line): bool
    {
        return str_starts_with($line, 'PosKunde')
            || str_starts_with($line, 'Seite ')
            || str_starts_with($line, 'Powered by')
            || str_starts_with($line, '+++');
    }

    private function isPositionLine(string $line, string $section): bool
    {
        // Positions are 1–3 digits; German PLZ is 5 digits and must not match.
        if (!preg_match('/^\d{1,3}(?:\s+\S.*)?$/u', $line)) {
            return false;
        }

        if ($this->isProductStart($line) || $this->isWeightLine($line) || $this->isAddressStart($line)) {
            return false;
        }

        // Street house numbers during address collection are not new positions.
        if ('address' === $section) {
            return false;
        }

        // New stops appear before name collection or after remarks/weight.
        if (\in_array($section, ['contact', 'lp', 'products'], true)) {
            return false;
        }

        return \in_array($section, ['idle', 'name', 'tail'], true);
    }

    private function isAddressStart(string $line): bool
    {
        return 1 === preg_match('/^\d{5}\b/u', $line);
    }

    private function isContactLine(string $line): bool
    {
        if ($this->looksLikeEmail($line)) {
            return true;
        }

        // Phone-like: starts with + or digit, contains enough digits, may end with comma.
        $digits = preg_replace('/\D+/', '', $line) ?? '';
        if (\strlen($digits) >= 6 && 1 === preg_match('/^[+\d]/u', $line)) {
            return true;
        }

        return false;
    }

    private function looksLikeEmail(string $line): bool
    {
        return 1 === preg_match('/[^\s,]+@[^\s,]+/u', $line);
    }

    private function isLpLine(string $line): bool
    {
        return 1 === preg_match('/^\d{1,3}(?:\s+\d+\s*x\s+.+)?$/iu', $line);
    }

    private function isProductStart(string $line): bool
    {
        return 1 === preg_match('/^\d+\s*x\s+/iu', $line);
    }

    private function isWeightLine(string $line): bool
    {
        return 1 === preg_match('/^\d+,\d+\s*$/u', $line);
    }

    private function isRemarkLine(string $line): bool
    {
        return str_contains($line, 'Tel. Avis')
            || str_starts_with($line, 'TAUSCH');
    }

    /**
     * @param array<string, mixed> $current
     */
    private function applyLpLine(array &$current, string $line): void
    {
        if (preg_match('/^\d{1,3}\s+(\d+\s*x\s+.+)$/iu', $line, $m)) {
            $this->pushProduct($current, $m[1]);

            return;
        }
        // LP-only line — ignored for order fields.
    }

    /**
     * @param array<string, mixed> $current
     */
    private function pushProduct(array &$current, string $line): void
    {
        $this->flushProductBuffer($current);
        $current['productBuffer'] = trim($line);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function flushProductBuffer(array &$current): void
    {
        if (null === $current['productBuffer'] || '' === trim((string) $current['productBuffer'])) {
            $current['productBuffer'] = null;

            return;
        }

        $current['products'][] = trim(preg_replace('/\s+/u', ' ', (string) $current['productBuffer']) ?? '');
        $current['productBuffer'] = null;
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return array{
     *     position: int,
     *     clientName: string,
     *     phone: string|null,
     *     address: string,
     *     products: list<string>
     * }
     */
    private function finalizeOrder(array $current): array
    {
        $this->flushProductBuffer($current);

        $clientName = trim(preg_replace('/\s+/u', ' ', implode(' ', $current['nameParts'])) ?? '');
        $address = trim(preg_replace('/\s+/u', ' ', implode(' ', $current['addressParts'])) ?? '');
        $phone = $this->extractPhone($current['contactParts']);

        if ('' === $clientName) {
            throw new \InvalidArgumentException(sprintf('Order position %d has no client name', $current['position']));
        }
        if ('' === $address) {
            throw new \InvalidArgumentException(sprintf('Order position %d has no address', $current['position']));
        }

        /** @var list<string> $products */
        $products = array_values(array_filter(
            $current['products'],
            static fn ($p): bool => \is_string($p) && '' !== trim($p),
        ));

        return [
            'position' => $current['position'],
            'clientName' => $clientName,
            'phone' => $phone,
            'address' => $address,
            'products' => $products,
        ];
    }

    /**
     * @param list<string> $contactParts
     */
    private function extractPhone(array $contactParts): ?string
    {
        $blob = trim(preg_replace('/\s+/u', ' ', implode(' ', $contactParts)) ?? '');
        if ('' === $blob) {
            return null;
        }

        // Prefer the part before the first email.
        if (preg_match('/^(.*?)(?:,\s*)?[^\s,]+@[^\s,]+/u', $blob, $m)) {
            $phone = trim($m[1], " \t,");
            if ('' !== $phone) {
                return $phone;
            }
        }

        $digits = preg_replace('/\D+/', '', $blob) ?? '';
        if (\strlen($digits) >= 6) {
            // Keep original phone-looking segment without email.
            $withoutEmail = trim(preg_replace('/[^\s,]+@[^\s,]+/u', '', $blob) ?? '', " \t,");

            return '' !== $withoutEmail ? $withoutEmail : null;
        }

        return null;
    }
}
