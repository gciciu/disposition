<?php

namespace App\Service;

use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfCompany;
use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfDocument;
use App\Entity\DeliveryOrder;
use App\Enum\DeliveryMethod;
use App\Enum\DeliveryOrderStatus;
use App\Enum\DeliveryProofType;
use App\Util\AddressFormatter;

/**
 * Builds a Lieferbestätigung document from a completed DeliveryOrder.
 *
 * Fields not yet stored on the entity can be supplied via $overrides:
 * - customerEmail
 * - weightKg
 * - additionalInfo
 * - routeLabel / totalWeightKg / totalDistanceKm
 * - deliveryMethod
 * - company (partial override of letterhead)
 * - documentTitle / statusLabel / proofSectionTitle
 */
class DeliveryConfirmationPdfBuilder
{
    /**
     * @param array{
     *     name?: string,
     *     address?: string,
     *     phone?: string,
     *     email?: string,
     *     website?: string
     * } $companyDefaults
     */
    public function __construct(
        private readonly DeliveryProofService $proofService,
        private readonly array $companyDefaults,
    ) {
    }

    /**
     * Demo Lieferbestätigung with fixed sample data (no DB order required).
     * Used for local email / Mailpit tests.
     */
    public function buildSample(?string $customerEmail = null): DeliveryConfirmationPdfDocument
    {
        $now = new \DateTimeImmutable();
        $createdLabel = $this->formatDateTime($now);

        return new DeliveryConfirmationPdfDocument(
            company: $this->buildCompany([]),
            documentTitle: 'Lieferbestätigung',
            routeLabel: 'KW 44 H. Wagner 949 KG 1356 KM',
            position: 9,
            createdAtLabel: $createdLabel,
            statusLabel: 'Geliefert',
            customerName: 'Max Mustermann',
            customerAddress: 'Musterstraße 12, 34117 Kassel',
            customerPhone: '+49 561 1234567',
            customerEmail: $customerEmail ?? 'kunde@example.com',
            driverName: 'Testfahrer',
            deliveryAtLabel: $createdLabel,
            deliveryMethod: DeliveryConfirmationPdfDocument::METHOD_LEFT_ON_SITE,
            products: [
                '1 × HZ BS White / 19 mm, 120 × 60 cm / White',
                '2 × HZ Anthracite / 19 mm, 126 × 30 cm / Anthracite',
            ],
            weightKg: 24.5,
            additionalInfo: 'Phone notice 1 day in advance (sample data)',
            proofImageDataUri: null,
            proofSectionTitle: 'Lieferfoto',
            proofIsSignature: false,
            productBackgroundColors: [],
        );
    }

    /**
     * @param array{
     *     customerEmail?: string|null,
     *     weightKg?: float|null,
     *     additionalInfo?: string|null,
     *     routeLabel?: string|null,
     *     totalWeightKg?: float|null,
     *     totalDistanceKm?: float|null,
     *     deliveryMethod?: string|null,
     *     documentTitle?: string|null,
     *     statusLabel?: string|null,
     *     proofSectionTitle?: string|null,
     *     company?: array{
     *         name?: string,
     *         address?: string,
     *         phone?: string,
     *         email?: string,
     *         website?: string
     *     }
     * } $overrides
     */
    public function build(DeliveryOrder $order, array $overrides = []): DeliveryConfirmationPdfDocument
    {
        $route = $order->getRoute();
        $completedAt = $order->getCompletedAt() ?? new \DateTimeImmutable();
        $createdLabel = $this->formatDateTime($completedAt);

        $distanceKm = null !== $route?->getTotalDistanceMeters()
            ? round($route->getTotalDistanceMeters() / 1000, 1)
            : ($overrides['totalDistanceKm'] ?? null);

        $routeLabel = $overrides['routeLabel']
            ?? $this->buildRouteLabel(
                $route?->getName() ?? '',
                $overrides['totalWeightKg'] ?? null,
                $distanceKm,
            );

        $proofType = $order->getProofType();
        $deliveryMethod = $overrides['deliveryMethod']
            ?? $this->defaultDeliveryMethod($order->getDeliveryMethod(), $proofType);

        $delivered = $this->deliveredProductsWithColors($order);
        $additionalInfo = $overrides['additionalInfo'] ?? $this->defaultAdditionalInfo($order);
        $isSignature = DeliveryProofType::Signature === $proofType;

        return new DeliveryConfirmationPdfDocument(
            company: $this->buildCompany($overrides['company'] ?? []),
            documentTitle: $overrides['documentTitle'] ?? 'Lieferbestätigung',
            routeLabel: $routeLabel,
            position: $order->getPosition(),
            createdAtLabel: $createdLabel,
            statusLabel: $overrides['statusLabel'] ?? $this->statusLabel($order->getStatus()),
            customerName: $order->getClientName(),
            customerAddress: AddressFormatter::withoutCountry(
                $order->getFormattedAddress() ?? $order->getAddress(),
            ) ?? $order->getAddress(),
            customerPhone: $order->getPhone(),
            customerEmail: \array_key_exists('customerEmail', $overrides)
                ? $overrides['customerEmail']
                : $order->getEmail(),
            driverName: $route?->getCourier()?->getUser()?->getName() ?? '',
            deliveryAtLabel: $createdLabel,
            deliveryMethod: $deliveryMethod,
            products: $delivered['products'],
            weightKg: $overrides['weightKg'] ?? null,
            additionalInfo: $additionalInfo,
            proofImageDataUri: $this->proofDataUri($order),
            proofSectionTitle: $overrides['proofSectionTitle']
                ?? $this->defaultProofSectionTitle($proofType),
            proofIsSignature: $isSignature,
            productBackgroundColors: $delivered['productBackgroundColors'],
        );
    }

    /**
     * @param array{name?: string, address?: string, phone?: string, email?: string, website?: string} $override
     */
    private function buildCompany(array $override): DeliveryConfirmationPdfCompany
    {
        $merged = array_merge($this->companyDefaults, $override);

        return new DeliveryConfirmationPdfCompany(
            name: (string) ($merged['name'] ?? ''),
            address: (string) ($merged['address'] ?? ''),
            phone: (string) ($merged['phone'] ?? ''),
            email: (string) ($merged['email'] ?? ''),
            website: (string) ($merged['website'] ?? ''),
        );
    }

    /**
     * @return array{products: list<string>, productBackgroundColors: list<string|null>}
     */
    private function deliveredProductsWithColors(DeliveryOrder $order): array
    {
        $all = $order->getProducts();
        $colors = $order->getProductBackgroundColors() ?? [];
        $indexes = $order->getDeliveredProductIndexes();

        if (null === $indexes) {
            $alignedColors = [];
            foreach (array_keys($all) as $index) {
                $alignedColors[] = $colors[$index] ?? null;
            }

            return [
                'products' => $all,
                'productBackgroundColors' => $alignedColors,
            ];
        }

        $selected = [];
        $selectedColors = [];
        foreach ($indexes as $index) {
            if (!isset($all[$index])) {
                continue;
            }
            $selected[] = $all[$index];
            $selectedColors[] = $colors[$index] ?? null;
        }

        return [
            'products' => $selected,
            'productBackgroundColors' => $selectedColors,
        ];
    }

    private function proofDataUri(DeliveryOrder $order): ?string
    {
        $path = $this->proofService->absoluteProofPath($order);
        if (null === $path) {
            return null;
        }

        $binary = @file_get_contents($path);
        if (false === $binary || '' === $binary) {
            return null;
        }

        if (DeliveryProofType::Signature === $order->getProofType()) {
            $binary = $this->makeSignatureBackgroundTransparent($binary);

            return 'data:image/png;base64,'.base64_encode($binary);
        }

        $mime = $order->getProofMime() ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * Remove opaque white canvas fill so the signature does not appear framed.
     */
    private function makeSignatureBackgroundTransparent(string $pngBinary): string
    {
        if (!\function_exists('imagecreatefromstring')) {
            return $pngBinary;
        }

        $image = @imagecreatefromstring($pngBinary);
        if (false === $image) {
            return $pngBinary;
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $width = imagesx($image);
        $height = imagesy($image);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgba = imagecolorat($image, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if ($r > 245 && $g > 245 && $b > 245) {
                    imagesetpixel($image, $x, $y, $transparent);
                }
            }
        }

        ob_start();
        imagepng($image, null, 6);
        $out = ob_get_clean();
        imagedestroy($image);

        return false === $out || '' === $out ? $pngBinary : $out;
    }

    private function defaultDeliveryMethod(?DeliveryMethod $method, ?DeliveryProofType $proofType): string
    {
        if (null !== $method) {
            return $method->labelDe();
        }

        // Legacy fallback when deliveryMethod was not stored yet.
        return match ($proofType) {
            DeliveryProofType::Photo => DeliveryConfirmationPdfDocument::METHOD_LEFT_ON_SITE,
            DeliveryProofType::Signature => DeliveryConfirmationPdfDocument::METHOD_PERSONAL,
            default => DeliveryConfirmationPdfDocument::METHOD_LEFT_ON_SITE,
        };
    }

    private function defaultAdditionalInfo(DeliveryOrder $order): ?string
    {
        $parts = [];
        $neighbor = $order->getNeighborName();
        if (null !== $neighbor && '' !== $neighbor) {
            $parts[] = 'Nachbar: '.$neighbor;
        }
        $comment = $order->getCourierComment();
        if (null !== $comment && '' !== $comment) {
            $parts[] = $comment;
        }

        if ([] === $parts) {
            return null;
        }

        return implode("\n", $parts);
    }

    private function defaultProofSectionTitle(?DeliveryProofType $proofType): string
    {
        return match ($proofType) {
            DeliveryProofType::Signature => 'Unterschrift',
            default => 'Lieferfoto',
        };
    }

    private function statusLabel(DeliveryOrderStatus $status): string
    {
        return match ($status) {
            DeliveryOrderStatus::Delivered => 'Geliefert',
            DeliveryOrderStatus::Refused => 'Nicht geliefert',
            DeliveryOrderStatus::Pending => 'Ausstehend',
        };
    }

    private function buildRouteLabel(string $routeName, ?float $totalWeightKg, ?float $totalDistanceKm): string
    {
        $parts = [trim($routeName)];
        if (null !== $totalWeightKg) {
            $parts[] = sprintf('%s KG', $this->formatNumber($totalWeightKg));
        }
        if (null !== $totalDistanceKm) {
            $parts[] = sprintf('%s KM', $this->formatNumber($totalDistanceKm));
        }

        return implode(' ', array_filter($parts, static fn (string $p): bool => '' !== $p));
    }

    private function formatDateTime(\DateTimeImmutable $at): string
    {
        return $at->format('d.m.Y, H:i').' Uhr';
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.05) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
