<?php

namespace App\Service;

use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Enum\RouteOriginType;
use App\Exception\RouteAddressValidationException;
use App\Repository\WarehouseRepository;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RoutePdfImportService
{
    public function __construct(
        private readonly GoogleMapsService $googleMaps,
        private readonly DeliveryRouteMetricsService $metrics,
        private readonly WarehouseRepository $warehouses,
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

        $parsed['orders'] = $this->markAccessoryProducts($text, $parsed['orders']);
        $parsed['orders'] = $this->assignProductBackgroundColors($file, $parsed['orders']);

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

        $defaultWarehouse = $this->warehouses->findDefault();
        $route = (new DeliveryRoute())
            ->setName($parsed['routeName'])
            ->setSourceFilename($file->getClientOriginalName())
            ->setTotalWeightKg($parsed['totalWeightKg']);

        if (null !== $defaultWarehouse) {
            $route->setWarehouse($defaultWarehouse);
        } else {
            $route->setOriginType(RouteOriginType::CourierLocation);
        }

        $ordersWeightSum = 0.0;
        $ordersHaveWeight = false;

        foreach ($geocodedOrders as $orderData) {
            $orderWeight = isset($orderData['weightKg']) && is_numeric($orderData['weightKg'])
                ? (float) $orderData['weightKg']
                : null;
            if (null !== $orderWeight) {
                $ordersWeightSum += $orderWeight;
                $ordersHaveWeight = true;
            }

            $order = (new DeliveryOrder())
                // Keep PDF stop numbers (MySpiegel sheets start at Pos 2; Pos 1 is depot).
                ->setPosition((int) $orderData['position'])
                ->setClientName($orderData['clientName'])
                ->setPhone($orderData['phone'])
                ->setEmail($orderData['email'] ?? null)
                ->setAddress($orderData['address'])
                ->setAddressValid(true)
                ->setFormattedAddress($orderData['formattedAddress'])
                ->setLat($orderData['lat'])
                ->setLng($orderData['lng'])
                ->setPlaceId($orderData['placeId'])
                ->setWeightKg($orderWeight)
                ->setComments($this->buildImportComments($orderData['comments'] ?? []))
                ->setProducts($orderData['products'])
                ->setProductBackgroundColors($orderData['productBackgroundColors'] ?? null)
                ->setAccessoryProductIndexes($orderData['accessoryProductIndexes'] ?? null);

            $route->addOrder($order);
        }

        if (null === $route->getTotalWeightKg() && $ordersHaveWeight) {
            $route->setTotalWeightKg(round($ordersWeightSum, 1));
        }

        $this->metrics->rebuild($route);

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
     *         email: string|null,
     *         address: string,
     *         weightKg: float|null,
     *         comments: list<string>,
     *         products: list<string>,
     *         productBackgroundColors?: list<string|null>,
     *         accessoryProductIndexes?: list<int>
     *     }>
     * }
     */
    public function parseFirstTable(string $text): array
    {
        return $this->parseOrdersTable($this->cutToFirstTable($text));
    }

    /**
     * @return array{
     *     routeName: string,
     *     totalWeightKg: float|null,
     *     orders: list<array{
     *         position: int,
     *         clientName: string,
     *         phone: string|null,
     *         email: string|null,
     *         address: string,
     *         weightKg: float|null,
     *         comments: list<string>,
     *         products: list<string>
     *     }>
     * }
     */
    private function parseOrdersTable(string $tableText): array
    {
        $header = $this->extractRouteHeader($tableText);
        $routeName = $header['routeName'] ?? 'Imported route';
        $lines = preg_split('/\R/u', $tableText) ?: [];

        $orders = [];
        $current = null;
        $section = 'idle'; // idle|name|address|contact|lp|products|tail

        foreach ($lines as $rawLine) {
            $line = $this->normalizePdfLine($rawLine);
            if ('' === $line) {
                continue;
            }

            if ($this->isSkippableHeader($line)) {
                continue;
            }

            $positionMatch = $this->isPositionLine($line, $section) ? $this->matchPositionStart($line) : null;
            if (null !== $positionMatch) {
                if (null !== $current) {
                    $orders[] = $this->finalizeOrder($current);
                }

                $nameRemainder = $positionMatch['remainder'];
                $split = '' !== $nameRemainder ? $this->splitNameAndAddress($nameRemainder) : null;

                $current = [
                    'position' => $positionMatch['position'],
                    'clientName' => '',
                    'phone' => null,
                    'address' => '',
                    'products' => [],
                    'weightKg' => null,
                    'commentParts' => [],
                    'nameParts' => null !== $split
                        ? ('' !== $split['name'] ? [$split['name']] : [])
                        : ('' !== $nameRemainder ? [$nameRemainder] : []),
                    'addressParts' => null !== $split && '' !== $split['address'] ? [$split['address']] : [],
                    'contactParts' => [],
                    'productBuffer' => null,
                ];
                $section = null !== $split ? 'address' : 'name';
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

                $split = $this->splitNameAndAddress($line);
                if (null !== $split) {
                    if ('' !== $split['name']) {
                        $current['nameParts'][] = $split['name'];
                    }
                    if ('' !== $split['address']) {
                        $current['addressParts'][] = $split['address'];
                    }
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
                if ($this->isLpLine($line) || $this->isProductStart($line)) {
                    $section = $this->ingestArticleLine($current, $line);
                    continue;
                }
                if ($this->isContactLine($line) || $this->looksLikeEmail($line)) {
                    $current['contactParts'][] = $line;
                    continue;
                }
                $current['contactParts'][] = $line;
                continue;
            }

            if ('products' === $section || 'tail' === $section) {
                if ($this->isWeightLine($line) || $this->isWeightRemarkLine($line)) {
                    $this->flushProductBuffer($current);
                    $weightRemark = $this->splitWeightAndRemark($line);
                    $current['weightKg'] = $weightRemark['weight'];
                    if (null !== $weightRemark['remark']) {
                        $current['commentParts'][] = $weightRemark['remark'];
                    }
                    $section = 'tail';
                    continue;
                }
                if ($this->isRemarkLine($line)) {
                    $this->flushProductBuffer($current);
                    $current['commentParts'][] = $line;
                    $section = 'tail';
                    continue;
                }

                if ($this->isLpLine($line) || $this->isProductStart($line)) {
                    $section = $this->ingestArticleLine($current, $line);
                    continue;
                }

                if ('products' === $section && null !== $current['productBuffer']) {
                    $current['productBuffer'] .= ' '.$line;
                    continue;
                }

                // EN PDFs wrap remarks across lines ("Phone notice 1 day in" / "advance").
                if (
                    'tail' === $section
                    && [] !== $current['commentParts']
                    && 1 === preg_match('/^[a-zäöü][a-zäöü\s.,;:!\'-]{0,40}$/u', $line)
                ) {
                    $last = array_key_last($current['commentParts']);
                    $current['commentParts'][$last] .= ' '.$line;
                    continue;
                }
            }
        }

        if (null !== $current) {
            $orders[] = $this->finalizeOrder($current);
        }

        return [
            'routeName' => $routeName,
            'totalWeightKg' => $header['totalWeightKg'],
            'orders' => $orders,
        ];
    }

    private function cutToFirstTable(string $text): string
    {
        $parts = preg_split('/^\s*Zubehör\b.*$/um', $text, 2);

        return trim($parts[0] ?? $text);
    }

    /**
     * Text after the "Zubehör" heading, excluding the totals summary.
     */
    private function cutAccessoriesTable(string $text): string
    {
        $parts = preg_split('/^\s*Zubehör\b.*$/um', $text, 2);
        $after = trim($parts[1] ?? '');
        if ('' === $after) {
            return '';
        }

        $parts = preg_split('/^\s*Gesamtmengen des Zubehörs\b.*$/um', $after, 2);

        return trim($parts[0] ?? $after);
    }

    /**
     * @return array<int, list<string>> PDF stop position => accessory product lines
     */
    public function parseAccessoriesTable(string $text): array
    {
        $accessoriesText = $this->cutAccessoriesTable($text);
        if ('' === $accessoriesText) {
            return [];
        }

        $parsed = $this->parseOrdersTable($accessoriesText);
        $byPosition = [];
        foreach ($parsed['orders'] as $order) {
            $position = (int) ($order['position'] ?? 0);
            if ($position < 1) {
                continue;
            }
            /** @var list<string> $products */
            $products = array_values(array_filter(
                $order['products'] ?? [],
                static fn ($product): bool => \is_string($product) && '' !== trim($product),
            ));
            if ([] === $products) {
                continue;
            }
            $byPosition[$position] = $products;
        }

        return $byPosition;
    }

    /**
     * Flag products that also appear in the PDF "Zubehör" table for the same stop.
     *
     * @param list<array<string, mixed>> $orders
     *
     * @return list<array<string, mixed>>
     */
    public function markAccessoryProducts(string $text, array $orders): array
    {
        $accessoriesByPosition = $this->parseAccessoriesTable($text);

        foreach ($orders as $orderIndex => $order) {
            $position = (int) ($order['position'] ?? 0);
            $products = $order['products'] ?? [];
            if (!\is_array($products) || [] === $products) {
                $orders[$orderIndex]['accessoryProductIndexes'] = [];
                continue;
            }

            $accessoryLines = $accessoriesByPosition[$position] ?? [];
            if ([] === $accessoryLines) {
                $orders[$orderIndex]['accessoryProductIndexes'] = [];
                continue;
            }

            $accessoryKeys = [];
            foreach ($accessoryLines as $line) {
                $accessoryKeys[$this->normalizeProductKey($line)] = true;
            }

            $indexes = [];
            foreach ($products as $productIndex => $product) {
                if (!\is_string($product)) {
                    continue;
                }
                if (isset($accessoryKeys[$this->normalizeProductKey($product)])) {
                    $indexes[] = (int) $productIndex;
                }
            }

            $orders[$orderIndex]['accessoryProductIndexes'] = $indexes;
        }

        return $orders;
    }

    private function normalizeProductKey(string $product): string
    {
        $normalized = $this->normalizePdfLine($product);
        $normalized = mb_strtolower($normalized);

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    /**
     * @return array{routeName: string|null, totalWeightKg: float|null}
     */
    private function extractRouteHeader(string $text): array
    {
        // Prefer the first "Seite n/m … (123,4kg)" header line.
        if (preg_match(
            '/Seite\s+\d+\s*\/\s*\d+\s+([^\r\n]*?)\((\d+[.,]\d+)\s*kg\)/iu',
            $text,
            $m,
        )) {
            $name = $this->normalizePdfLine($m[1]);
            $name = preg_replace('/\s+\(\d+[.,]\d+\s*kg\)\s*$/iu', '', $name) ?? $name;

            return [
                'routeName' => trim($name),
                'totalWeightKg' => $this->parseGermanFloat($m[2]),
            ];
        }

        if (preg_match('/Seite\s+\d+\s*\/\s*\d+\s+(.+?)(?:\r?\n|$)/u', $text, $m)) {
            $name = $this->normalizePdfLine($m[1]);
            $totalWeightKg = null;
            if (preg_match('/\((\d+[.,]\d+)\s*kg\)\s*$/iu', $name, $wm)) {
                $totalWeightKg = $this->parseGermanFloat($wm[1]);
                $name = preg_replace('/\s+\(\d+[.,]\d+\s*kg\)\s*$/iu', '', $name) ?? $name;
            }

            return [
                'routeName' => trim($name),
                'totalWeightKg' => $totalWeightKg,
            ];
        }

        return [
            'routeName' => null,
            'totalWeightKg' => null,
        ];
    }

    private function normalizePdfLine(string $line): string
    {
        $line = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\t"], ' ', $line);

        return trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    }

    private function parseGermanFloat(string $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], $this->normalizePdfLine($value));

        return round((float) $normalized, 1);
    }

    private function isSkippableHeader(string $line): bool
    {
        return str_starts_with($line, 'PosKunde')
            || str_starts_with($line, 'Pos.Customer')
            || str_starts_with($line, 'Seite ')
            || str_starts_with($line, 'Zubehör')
            || str_starts_with($line, 'Powered by')
            || str_starts_with($line, 'Route sheet')
            || str_starts_with($line, 'DELIVERY ROUTE')
            || str_starts_with($line, 'Route:')
            || str_starts_with($line, 'Signature Left with')
            || str_starts_with($line, 'kgDate')
            // Wrapped EN header fragment: "... Deliver" / "ed"
            || 'ed' === $line
            // Pure +++ separators only — "+++ Tel. Avis … +++" is a remark line.
            || 1 === preg_match('/^\+{3,}$/u', $line);
    }

    /**
     * @return array{position: int, remainder: string}|null
     */
    private function matchPositionStart(string $line): ?array
    {
        if (preg_match('/^(\d{1,3})$/u', $line, $m)) {
            return ['position' => (int) $m[1], 'remainder' => ''];
        }

        // "2 Irina Tissen"
        if (preg_match('/^(\d{1,3})\s+(.+)$/u', $line, $m)) {
            return ['position' => (int) $m[1], 'remainder' => trim($m[2])];
        }

        // EN PDFs often glue Pos+name: "3Rewe Andy Müller…"
        if (preg_match('/^(\d{1,3})([A-Za-zÀ-ÖØ-öø-ÿ].*)$/u', $line, $m)) {
            return ['position' => (int) $m[1], 'remainder' => trim($m[2])];
        }

        return null;
    }

    private function isPositionLine(string $line, string $section): bool
    {
        if (null === $this->matchPositionStart($line)) {
            return false;
        }

        // LP+Artikel ("361 × …") and Artikel ("1 x …") must not become a new Pos.
        if (
            $this->isProductStart($line)
            || null !== $this->splitLpPrefixedProduct($line)
            || $this->isWeightLine($line)
            || $this->isWeightRemarkLine($line)
            || $this->isAddressStart($line)
        ) {
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

    /**
     * Name and address may share one line: "Client Name, 12345 City, Street".
     * Split at the first comma that is followed by a German PLZ.
     *
     * @return array{name: string, address: string}|null
     */
    private function splitNameAndAddress(string $line): ?array
    {
        if (!preg_match('/^(.*),\s*(\d{5}\b.*)$/u', $line, $m)) {
            return null;
        }

        return [
            'name' => trim($m[1], " \t,"),
            'address' => trim($m[2]),
        ];
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
        if (null !== $this->splitLpPrefixedProduct($line)) {
            return true;
        }

        // LP-only ("36") or spaced "60 1 x …".
        return 1 === preg_match('/^\d{1,3}(?:\s+\d+\s*[x×]\s+.+)?$/iu', $line);
    }

    private function isProductStart(string $line): bool
    {
        // ASCII "x" (DE MySpiegel) and unicode "×" (EN translations).
        return 1 === preg_match('/^\d+\s*[x×]\s+/iu', $line);
    }

    private function isWeightLine(string $line): bool
    {
        return 1 === preg_match('/^\d+[.,]\d+\s*$/u', $line);
    }

    private function isWeightRemarkLine(string $line): bool
    {
        return 1 === preg_match('/^\d+[.,]\d+\s+\S/u', $line) && null !== $this->splitWeightAndRemark($line)['remark'];
    }

    private function isRemarkLine(string $line): bool
    {
        return str_contains($line, 'Tel. Avis')
            || str_contains($line, 'Phone notice')
            || str_starts_with($line, 'TAUSCH')
            || str_starts_with($line, 'EXCHANGE')
            || str_starts_with($line, '+++');
    }

    /**
     * EN sheets glue LP + qty: "361 × HZ…" → product "1 × HZ…".
     * DE sheets use a space: "60 1 x Master…".
     */
    private function splitLpPrefixedProduct(string $line): ?string
    {
        // Glued LP(2 digits) + qty: needs ≥3 digits before the multiplier.
        if (preg_match('/^\d{2}(\d+\s*[x×]\s+.+)$/iu', $line, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/^\d{1,3}\s+(\d+\s*[x×]\s+.+)$/iu', $line, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @return array{text: string, weight: float|null, remark: string|null}
     */
    private function peelWeightAndRemark(string $line): array
    {
        if (preg_match(
            '/^(.*?)\s+(\d+[.,]\d+)\s+(Phone notice\b.*|Tel\.\s*Avis\b.*|TAUSCH\b.*|EXCHANGE\b.*|\+{3,}.*)$/iu',
            $line,
            $m,
        )) {
            return [
                'text' => trim($m[1]),
                'weight' => $this->parseGermanFloat($m[2]),
                'remark' => trim($m[3]),
            ];
        }

        if (
            preg_match('/^(.*?)\s+(\d+[.,]\d+)\s*$/u', $line, $m)
            && $this->isProductStart(trim($m[1]))
        ) {
            return [
                'text' => trim($m[1]),
                'weight' => $this->parseGermanFloat($m[2]),
                'remark' => null,
            ];
        }

        return ['text' => $line, 'weight' => null, 'remark' => null];
    }

    /**
     * @return array{weight: float, remark: string|null}
     */
    private function splitWeightAndRemark(string $line): array
    {
        if (preg_match(
            '/^(\d+[.,]\d+)\s+(Phone notice\b.*|Tel\.\s*Avis\b.*|TAUSCH\b.*|EXCHANGE\b.*|\+{3,}.*)$/iu',
            $line,
            $m,
        )) {
            return [
                'weight' => $this->parseGermanFloat($m[1]),
                'remark' => trim($m[2]),
            ];
        }

        return [
            'weight' => $this->parseGermanFloat($line),
            'remark' => null,
        ];
    }

    /**
     * Consume an LP / Artikel line (possibly with trailing weight + remark).
     *
     * @param array<string, mixed> $current
     *
     * @return 'products'|'tail'
     */
    private function ingestArticleLine(array &$current, string $line): string
    {
        $productLine = $this->splitLpPrefixedProduct($line);
        if (null === $productLine) {
            if ($this->isProductStart($line)) {
                $productLine = $line;
            } else {
                // LP-only — ignored for order fields.
                return 'products';
            }
        }

        $peeled = $this->peelWeightAndRemark($productLine);
        $this->pushProduct($current, $peeled['text']);

        if (null !== $peeled['weight']) {
            $this->flushProductBuffer($current);
            $current['weightKg'] = $peeled['weight'];
        }
        if (null !== $peeled['remark']) {
            $this->flushProductBuffer($current);
            $current['commentParts'][] = $peeled['remark'];

            return 'tail';
        }

        return null !== $peeled['weight'] ? 'tail' : 'products';
    }

    /**
     * @param array<string, mixed> $current
     */
    private function pushProduct(array &$current, string $line): void
    {
        $this->flushProductBuffer($current);
        // Normalize unicode multiplication sign so products match DE accessory keys.
        $normalized = preg_replace('/^(\d+)\s*[x×]\s+/iu', '$1 x ', trim($line)) ?? trim($line);
        $current['productBuffer'] = $normalized;
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

        $product = trim(preg_replace('/\s+/u', ' ', (string) $current['productBuffer']) ?? '');
        $current['productBuffer'] = null;

        // Import products always start with a quantity like "1 x …" / "2 x …".
        if (!$this->isProductStart($product)) {
            return;
        }

        $current['products'][] = $product;
    }

    /**
     * @param array<string, mixed> $current
     *
     * @return array{
     *     position: int,
     *     clientName: string,
     *     phone: string|null,
     *     email: string|null,
     *     address: string,
     *     weightKg: float|null,
     *     comments: list<string>,
     *     products: list<string>
     * }
     */
    private function finalizeOrder(array $current): array
    {
        $this->flushProductBuffer($current);

        $clientName = trim(preg_replace('/\s+/u', ' ', implode(' ', $current['nameParts'])) ?? '');
        $address = trim(preg_replace('/\s+/u', ' ', implode(' ', $current['addressParts'])) ?? '');
        $phone = $this->extractPhone($current['contactParts']);
        $email = $this->extractEmail($current['contactParts']);

        if ('' === $clientName) {
            throw new \InvalidArgumentException(sprintf('Order position %d has no client name', $current['position']));
        }
        if ('' === $address) {
            throw new \InvalidArgumentException(sprintf('Order position %d has no address', $current['position']));
        }

        /** @var list<string> $products */
        $products = array_values(array_filter(
            $current['products'],
            fn ($p): bool => \is_string($p) && '' !== trim($p) && $this->isProductStart(trim($p)),
        ));

        $weightKg = isset($current['weightKg']) && is_numeric($current['weightKg'])
            ? (float) $current['weightKg']
            : null;

        // Each remark line is a separate comment (do not join multi-line remarks).
        $comments = [];
        foreach ($current['commentParts'] ?? [] as $part) {
            if (!\is_string($part)) {
                continue;
            }
            $value = trim($part);
            if ('' !== $value) {
                $comments[] = $value;
            }
        }

        return [
            'position' => $current['position'],
            'clientName' => $clientName,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'weightKg' => $weightKg,
            'comments' => $comments,
            'products' => $products,
        ];
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{id: string, text: string, color: string}>
     */
    private function buildImportComments(array $texts): array
    {
        $comments = [];
        $index = 0;
        foreach ($texts as $text) {
            if (!\is_string($text)) {
                continue;
            }
            $value = trim($text);
            if ('' === $value) {
                continue;
            }

            if (0 === $index) {
                $color = str_starts_with(mb_strtoupper($value), 'TAUSCH')
                    ? 'red'
                    : 'blue';
            } else {
                $color = DeliveryOrder::COMMENT_COLOR_DEFAULT;
            }

            $comments[] = [
                'id' => DeliveryOrder::newCommentId(),
                'text' => $value,
                'color' => $color,
            ];
            ++$index;
        }

        return $comments;
    }

    /**
     * Attach PDF article-cell background colors to each product, in document order.
     *
     * @param list<array<string, mixed>> $orders
     *
     * @return list<array<string, mixed>>
     */
    private function assignProductBackgroundColors(UploadedFile $file, array $orders): array
    {
        $queue = $this->extractProductBackgroundColorQueue($file);
        $queueIndex = 0;

        foreach ($orders as $orderIndex => $order) {
            $products = $order['products'] ?? [];
            if (!\is_array($products) || [] === $products) {
                $orders[$orderIndex]['productBackgroundColors'] = [];
                continue;
            }

            $colors = [];
            foreach ($products as $_) {
                $colors[] = $queue[$queueIndex] ?? null;
                ++$queueIndex;
            }
            $orders[$orderIndex]['productBackgroundColors'] = $colors;
        }

        return $orders;
    }

    /**
     * @return list<string|null> hex colors for each product-start line across the PDF
     */
    private function extractProductBackgroundColorQueue(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (false === $path || !is_readable($path)) {
            return [];
        }

        try {
            $pdf = (new Parser())->parseFile($path);
        } catch (\Throwable) {
            return [];
        }

        $queue = [];
        foreach ($pdf->getPages() as $page) {
            if (!$page instanceof Page) {
                continue;
            }

            $fills = $this->extractArticleFillsFromPage($page);
            foreach ($page->getDataTm() as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', (string) ($row[1] ?? '')) ?? '');
                if ('' === $text || !$this->isProductStart($text)) {
                    continue;
                }

                $y = isset($row[0][5]) && is_numeric($row[0][5]) ? (float) $row[0][5] : null;
                if (null === $y) {
                    $queue[] = null;
                    continue;
                }

                $queue[] = $this->matchFillColorAtY($fills, $y);
            }
        }

        return $queue;
    }

    /**
     * @return list<array{hex: string, x: float, y: float, w: float, h: float}>
     */
    private function extractArticleFillsFromPage(Page $page): array
    {
        $raw = $this->readPageContentStream($page);
        if ('' === $raw) {
            return [];
        }

        if (!preg_match_all('/(?:[+-]?(?:\d+\.\d*|\.\d+|\d+)|[A-Za-z]+)/', $raw, $matches)) {
            return [];
        }

        $tokens = $matches[0];
        $fills = [];
        $r = 0.0;
        $g = 0.0;
        $b = 0.0;
        $hasRgb = false;
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (
                'rg' === $token
                && $i >= 3
                && is_numeric($tokens[$i - 3])
                && is_numeric($tokens[$i - 2])
                && is_numeric($tokens[$i - 1])
            ) {
                $r = (float) $tokens[$i - 3];
                $g = (float) $tokens[$i - 2];
                $b = (float) $tokens[$i - 1];
                $hasRgb = true;
                continue;
            }

            if (
                're' !== $token
                || !$hasRgb
                || $i + 1 >= $count
                || 'f' !== $tokens[$i + 1]
                || $i < 4
                || !is_numeric($tokens[$i - 4])
                || !is_numeric($tokens[$i - 3])
                || !is_numeric($tokens[$i - 2])
                || !is_numeric($tokens[$i - 1])
            ) {
                continue;
            }

            $x = (float) $tokens[$i - 4];
            $y = (float) $tokens[$i - 3];
            $w = (float) $tokens[$i - 2];
            $h = (float) $tokens[$i - 1];
            if ($w < 0) {
                $x += $w;
                $w = -$w;
            }
            if ($h < 0) {
                $y += $h;
                $h = -$h;
            }

            // Artikel column cell fills in MySpiegel route sheets.
            if ($x < 200.0 || $x > 250.0 || $w < 140.0 || $h < 8.0 || $h > 50.0) {
                continue;
            }

            $hex = sprintf(
                '#%02X%02X%02X',
                (int) round($r * 255),
                (int) round($g * 255),
                (int) round($b * 255),
            );
            if ('#FFFFFF' === $hex || '#000000' === $hex) {
                continue;
            }

            $fills[] = [
                'hex' => $hex,
                'x' => $x,
                'y' => $y,
                'w' => $w,
                'h' => $h,
            ];
        }

        return $fills;
    }

    private function readPageContentStream(Page $page): string
    {
        $contents = $page->get('Contents');
        if ($contents instanceof PDFObject) {
            return (string) $contents->getContent();
        }

        if (!\is_array($contents)) {
            return '';
        }

        $raw = '';
        foreach ($contents as $part) {
            if ($part instanceof PDFObject) {
                $raw .= (string) $part->getContent();
            }
        }

        return $raw;
    }

    /**
     * @param list<array{hex: string, x: float, y: float, w: float, h: float}> $fills
     */
    private function matchFillColorAtY(array $fills, float $y): ?string
    {
        $bestHex = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($fills as $fill) {
            $y0 = $fill['y'];
            $y1 = $fill['y'] + $fill['h'];
            if ($y < $y0 - 3.0 || $y > $y1 + 10.0) {
                continue;
            }

            $distance = abs($y - (($y0 + $y1) / 2.0));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestHex = $fill['hex'];
            }
        }

        return $bestHex;
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

    /**
     * @param list<string> $contactParts
     */
    private function extractEmail(array $contactParts): ?string
    {
        $blob = trim(preg_replace('/\s+/u', ' ', implode(' ', $contactParts)) ?? '');
        if ('' === $blob) {
            return null;
        }

        if (preg_match('/([^\s,]+@[^\s,]+)/u', $blob, $m)) {
            $email = trim($m[1], " \t,");

            return '' !== $email ? $email : null;
        }

        return null;
    }
}
