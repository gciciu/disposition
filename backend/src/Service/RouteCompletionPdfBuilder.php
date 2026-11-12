<?php

namespace App\Service;

use App\Dto\RouteCompletionPdf\RouteCompletionPdfAccessory;
use App\Dto\RouteCompletionPdf\RouteCompletionPdfDocument;
use App\Dto\RouteCompletionPdf\RouteCompletionPdfHeader;
use App\Dto\RouteCompletionPdf\RouteCompletionPdfReturn;
use App\Dto\RouteCompletionPdf\RouteCompletionPdfStop;
use App\Entity\DeliveryOrder;
use App\Entity\DeliveryRoute;
use App\Enum\DeliveryOrderStatus;
use App\Enum\DeliveryProofType;
use App\Util\AddressFormatter;

/**
 * Builds the route completion PDF document from a DeliveryRoute.
 *
 * Currently maps fields that already exist on the entity.
 * Optional / future fields can be supplied via $overrides:
 * - totalWeightKg
 * - mapImageDataUri
 * - accessories: list of RouteCompletionPdfAccessory|array
 * - per-stop customerRemark / driverRemark / weightKg (keyed by order id or position)
 */
class RouteCompletionPdfBuilder
{
    public function __construct(
        private readonly DeliveryProofService $proofService,
        private readonly RouteOverviewMapService $overviewMap,
    ) {
    }

    /**
     * @param array{
     *     totalWeightKg?: float|null,
     *     mapImageDataUri?: string|null,
     *     accessories?: list<RouteCompletionPdfAccessory|array{position: int, description: string, delivered?: bool}>,
     *     stopExtras?: array<int, array{
     *         customerRemark?: string|null,
     *         driverRemark?: string|null,
     *         weightKg?: float|null
     *     }>
     * } $overrides
     */
    public function build(DeliveryRoute $route, array $overrides = []): RouteCompletionPdfDocument
    {
        $driverName = $route->getCourier()?->getUser()?->getName() ?? '';
        $warehouseAddress = $this->warehouseAddress($route);
        $distanceKm = null !== $route->getTotalDistanceMeters()
            ? round($route->getTotalDistanceMeters() / 1000, 1)
            : null;

        $reportDate = ($route->getCompletedAt() ?? $route->getStartedAt() ?? $route->getCreatedAt())
            ->format('d.m.Y');

        $title = $this->buildTitle(
            $route->getName(),
            $overrides['totalWeightKg'] ?? null,
            $distanceKm,
        );

        $mapImageDataUri = \array_key_exists('mapImageDataUri', $overrides)
            ? $overrides['mapImageDataUri']
            : $this->overviewMap->buildDataUri($route);

        $header = new RouteCompletionPdfHeader(
            title: $title,
            driverName: $driverName,
            totalWeightKg: $overrides['totalWeightKg'] ?? null,
            totalDistanceKm: $distanceKm,
            reportDate: $reportDate,
            fromAddress: $warehouseAddress,
            toAddress: $warehouseAddress,
            mapImageDataUri: $mapImageDataUri,
        );

        $stopExtras = $overrides['stopExtras'] ?? [];
        $stops = [];
        $returns = [];

        foreach ($route->getOrders() as $order) {
            $extra = $this->resolveStopExtra($order, $stopExtras);
            $stops[] = $this->buildStop($order, $extra);

            if (DeliveryOrderStatus::Refused === $order->getStatus()) {
                $returns[] = $this->buildReturnFromRefused(
                    $order,
                    $extra['customerRemark'] ?? $order->getCommentsText(),
                );
            }
        }

        $accessories = \array_key_exists('accessories', $overrides)
            ? $this->normalizeAccessories($overrides['accessories'] ?? [])
            : $this->buildAccessoriesFromOrders($route);

        $footerLabel = sprintf(
            'Route: %s%s',
            $title,
            '' !== $driverName ? ' | Fahrer: '.$driverName : '',
        );

        return new RouteCompletionPdfDocument(
            header: $header,
            stops: $stops,
            accessories: $accessories,
            returns: $returns,
            footerLabel: $footerLabel,
        );
    }

    /**
     * @param array{customerRemark?: string|null, driverRemark?: string|null, weightKg?: float|null} $extra
     */
    private function buildStop(DeliveryOrder $order, array $extra): RouteCompletionPdfStop
    {
        $completedAt = $order->getCompletedAt();
        $delivered = DeliveryOrderStatus::Delivered === $order->getStatus();

        return new RouteCompletionPdfStop(
            position: $order->getPosition(),
            clientName: $order->getClientName(),
            phone: $order->getPhone(),
            address: AddressFormatter::withoutCountry(
                $order->getFormattedAddress() ?? $order->getAddress(),
            ) ?? $order->getAddress(),
            products: $order->getProducts(),
            weightKg: $extra['weightKg'] ?? $order->getWeightKg(),
            date: $completedAt?->format('d.m.Y'),
            time: $completedAt?->format('H:i'),
            delivered: $delivered,
            signatureImageDataUri: $this->signatureDataUri($order),
            customerRemark: $extra['customerRemark'] ?? $order->getCommentsText(),
            driverRemark: $extra['driverRemark'] ?? null,
        );
    }

    private function buildReturnFromRefused(DeliveryOrder $order, ?string $customerRemark): RouteCompletionPdfReturn
    {
        return new RouteCompletionPdfReturn(
            position: $order->getPosition(),
            returnType: RouteCompletionPdfReturn::TYPE_WAREHOUSE_RETURN,
            clientName: $order->getClientName(),
            address: AddressFormatter::withoutCountry(
                $order->getFormattedAddress() ?? $order->getAddress(),
            ) ?? $order->getAddress(),
            products: $order->getProducts(),
            deliveredLabel: 'Nein, Durch Dispo geschlossen',
            remark: $customerRemark,
        );
    }

    private function signatureDataUri(DeliveryOrder $order): ?string
    {
        if (DeliveryProofType::Signature !== $order->getProofType()) {
            return null;
        }

        $path = $this->proofService->absoluteProofPath($order);
        if (null === $path) {
            return null;
        }

        $binary = @file_get_contents($path);
        if (false === $binary || '' === $binary) {
            return null;
        }

        $mime = $order->getProofMime() ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private function warehouseAddress(DeliveryRoute $route): ?string
    {
        $warehouse = $route->getWarehouse();
        if (null === $warehouse) {
            return null;
        }

        return AddressFormatter::withoutCountry(
            $warehouse->getFormattedAddress() ?? $warehouse->getAddress(),
        );
    }

    private function buildTitle(string $routeName, ?float $totalWeightKg, ?float $totalDistanceKm): string
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

    /**
     * @param array<int, array{customerRemark?: string|null, driverRemark?: string|null, weightKg?: float|null}> $stopExtras
     *
     * @return array{customerRemark?: string|null, driverRemark?: string|null, weightKg?: float|null}
     */
    private function resolveStopExtra(DeliveryOrder $order, array $stopExtras): array
    {
        $orderId = $order->getId();
        if (null !== $orderId && \array_key_exists($orderId, $stopExtras)) {
            return $stopExtras[$orderId];
        }

        $position = $order->getPosition();
        if (\array_key_exists($position, $stopExtras)) {
            return $stopExtras[$position];
        }

        return [];
    }

    /**
     * @return list<RouteCompletionPdfAccessory>
     */
    private function buildAccessoriesFromOrders(DeliveryRoute $route): array
    {
        $accessories = [];
        foreach ($route->getOrders() as $order) {
            $indexes = $order->getAccessoryProductIndexes() ?? [];
            if ([] === $indexes) {
                continue;
            }

            $products = $order->getProducts();
            $deliveredIndexes = $order->getDeliveredProductIndexes();
            $stopDelivered = DeliveryOrderStatus::Delivered === $order->getStatus();

            foreach ($indexes as $index) {
                if (!\array_key_exists($index, $products)) {
                    continue;
                }

                $delivered = $stopDelivered && (
                    null === $deliveredIndexes || \in_array($index, $deliveredIndexes, true)
                );

                $accessories[] = new RouteCompletionPdfAccessory(
                    position: $order->getPosition(),
                    description: $products[$index],
                    delivered: $delivered,
                );
            }
        }

        return $accessories;
    }

    /**
     * @param list<RouteCompletionPdfAccessory|array{position: int, description: string, delivered?: bool}> $accessories
     *
     * @return list<RouteCompletionPdfAccessory>
     */
    private function normalizeAccessories(array $accessories): array
    {
        $normalized = [];
        foreach ($accessories as $row) {
            if ($row instanceof RouteCompletionPdfAccessory) {
                $normalized[] = $row;
                continue;
            }

            $normalized[] = new RouteCompletionPdfAccessory(
                position: (int) $row['position'],
                description: (string) $row['description'],
                delivered: (bool) ($row['delivered'] ?? true),
            );
        }

        return $normalized;
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.05) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
