<?php

namespace App\Service;

use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfCompany;
use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfDocument;
use App\Entity\DeliveryOrder;
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
            ?? $this->defaultDeliveryMethod($proofType);

        $products = $this->deliveredProducts($order);

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
            customerEmail: $overrides['customerEmail'] ?? null,
            driverName: $route?->getCourier()?->getUser()?->getName() ?? '',
            deliveryAtLabel: $createdLabel,
            deliveryMethod: $deliveryMethod,
            products: $products,
            weightKg: $overrides['weightKg'] ?? null,
            additionalInfo: $overrides['additionalInfo'] ?? null,
            proofImageDataUri: $this->proofDataUri($order),
            proofSectionTitle: $overrides['proofSectionTitle']
                ?? $this->defaultProofSectionTitle($proofType),
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
     * @return list<string>
     */
    private function deliveredProducts(DeliveryOrder $order): array
    {
        $all = $order->getProducts();
        $indexes = $order->getDeliveredProductIndexes();

        if (null === $indexes) {
            return $all;
        }

        $selected = [];
        foreach ($indexes as $index) {
            if (isset($all[$index])) {
                $selected[] = $all[$index];
            }
        }

        return $selected;
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

        $mime = $order->getProofMime() ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private function defaultDeliveryMethod(?DeliveryProofType $proofType): string
    {
        return match ($proofType) {
            DeliveryProofType::Photo => DeliveryConfirmationPdfDocument::METHOD_LEFT_ON_SITE,
            DeliveryProofType::Signature => DeliveryConfirmationPdfDocument::METHOD_PERSONAL,
            default => DeliveryConfirmationPdfDocument::METHOD_LEFT_ON_SITE,
        };
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
