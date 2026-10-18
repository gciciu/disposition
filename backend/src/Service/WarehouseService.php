<?php

namespace App\Service;

use App\Entity\Warehouse;
use App\Repository\WarehouseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class WarehouseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WarehouseRepository $warehouseRepository,
        private readonly GoogleMapsService $googleMaps,
    ) {
    }

    /**
     * @return list<Warehouse>
     */
    public function list(): array
    {
        return $this->warehouseRepository->findAllOrderedByName();
    }

    /**
     * @param array{name?: mixed, address?: mixed, placeId?: mixed, isDefault?: mixed} $data
     */
    public function create(array $data): Warehouse
    {
        $warehouse = new Warehouse();
        $this->applyData($warehouse, $data, true);
        $this->em->persist($warehouse);
        $this->em->flush();

        return $warehouse;
    }

    /**
     * @param array{name?: mixed, address?: mixed, placeId?: mixed, isDefault?: mixed} $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $this->applyData($warehouse, $data, false);
        $this->em->flush();

        return $warehouse;
    }

    public function delete(Warehouse $warehouse): void
    {
        $this->em->remove($warehouse);
        $this->em->flush();
    }

    /**
     * @param array{name?: mixed, address?: mixed, placeId?: mixed, isDefault?: mixed} $data
     */
    private function applyData(Warehouse $warehouse, array $data, bool $isCreate): void
    {
        if ($isCreate || \array_key_exists('name', $data)) {
            $warehouse->setName($this->requireNonEmptyString($data, 'name'));
        }

        if ($isCreate || \array_key_exists('isDefault', $data)) {
            $isDefault = $isCreate
                ? $this->optionalBool($data, 'isDefault', false)
                : $this->requireBool($data, 'isDefault');
            $warehouse->setIsDefault($isDefault);
            if ($isDefault) {
                $this->warehouseRepository->clearDefaultExcept($warehouse);
            }
        }

        $addressChanged = $isCreate || \array_key_exists('address', $data) || \array_key_exists('placeId', $data);
        if (!$addressChanged) {
            return;
        }

        $address = $this->requireNonEmptyString($data, 'address');
        $placeId = $this->optionalString($data, 'placeId');

        $resolved = $this->resolveAddress($address, $placeId);
        $warehouse
            ->setAddress($resolved['address'])
            ->setFormattedAddress($resolved['formattedAddress'])
            ->setLat($resolved['lat'])
            ->setLng($resolved['lng'])
            ->setPlaceId($resolved['placeId']);
    }

    /**
     * @return array{
     *     address: string,
     *     formattedAddress: string,
     *     lat: float,
     *     lng: float,
     *     placeId: string|null
     * }
     */
    private function resolveAddress(string $address, ?string $placeId): array
    {
        if (!$this->googleMaps->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'GOOGLE_MAPS_API_KEY is not configured');
        }

        if (null !== $placeId) {
            $details = $this->googleMaps->getPlaceDetails($placeId);
            if ($details['valid'] ?? false) {
                $formatted = $details['formattedAddress'] ?? $address;
                $lat = $details['lat'] ?? null;
                $lng = $details['lng'] ?? null;
                if (null !== $lat && null !== $lng && \is_string($formatted) && '' !== $formatted) {
                    return [
                        'address' => $formatted,
                        'formattedAddress' => $formatted,
                        'lat' => $lat,
                        'lng' => $lng,
                        'placeId' => $details['placeId'] ?? $placeId,
                    ];
                }
            }
        }

        $geocode = $this->googleMaps->geocodeAddress($address);
        if (!($geocode['valid'] ?? false)) {
            throw new BadRequestHttpException($geocode['error'] ?? 'Address not found in Google Maps');
        }

        $formatted = $geocode['formattedAddress'] ?? $address;
        $lat = $geocode['lat'] ?? null;
        $lng = $geocode['lng'] ?? null;
        if (null === $lat || null === $lng || !\is_string($formatted) || '' === $formatted) {
            throw new BadRequestHttpException('Address not found in Google Maps');
        }

        return [
            'address' => $formatted,
            'formattedAddress' => $formatted,
            'lat' => $lat,
            'lng' => $lng,
            'placeId' => $geocode['placeId'] ?? $placeId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireNonEmptyString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new BadRequestHttpException(sprintf('%s is required', $key));
        }

        $value = trim($value);
        if ('' === $value) {
            throw new BadRequestHttpException(sprintf('%s is required', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        if (!\array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }

        if (!\is_string($data[$key])) {
            throw new BadRequestHttpException(sprintf('%s must be a string', $key));
        }

        $value = trim($data[$key]);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalBool(array $data, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $data)) {
            return $default;
        }

        return $this->requireBool($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            throw new BadRequestHttpException(sprintf('%s must be a boolean', $key));
        }

        return $value;
    }
}
