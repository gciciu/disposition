<?php

namespace App\Entity;

use App\Repository\DeliveryOrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryOrderRepository::class)]
#[ORM\Table(name: 'delivery_order')]
class DeliveryOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DeliveryRoute::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DeliveryRoute $route = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    private string $clientName = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 512)]
    private string $address = '';

    #[ORM\Column]
    private bool $addressValid = false;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $formattedAddress = null;

    #[ORM\Column(nullable: true)]
    private ?float $lat = null;

    #[ORM\Column(nullable: true)]
    private ?float $lng = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeId = null;

    /** Travel time from the previous stop; null for the first order. */
    #[ORM\Column(nullable: true)]
    private ?int $travelDurationSeconds = null;

    /** Travel distance from the previous stop; null for the first order. */
    #[ORM\Column(nullable: true)]
    private ?int $travelDistanceMeters = null;

    /**
     * Product lines, e.g. ["1 x HZ BS Weiß / 19 mm, 120 x 60 cm / Weiß"].
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $products = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoute(): ?DeliveryRoute
    {
        return $this->route;
    }

    public function setRoute(?DeliveryRoute $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function setClientName(string $clientName): static
    {
        $this->clientName = trim($clientName);

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $phone = null !== $phone ? trim($phone) : null;
        $this->phone = '' === $phone ? null : $phone;

        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = trim($address);

        return $this;
    }

    public function isAddressValid(): bool
    {
        return $this->addressValid;
    }

    public function setAddressValid(bool $addressValid): static
    {
        $this->addressValid = $addressValid;

        return $this;
    }

    public function getFormattedAddress(): ?string
    {
        return $this->formattedAddress;
    }

    public function setFormattedAddress(?string $formattedAddress): static
    {
        $formattedAddress = null !== $formattedAddress ? trim($formattedAddress) : null;
        $this->formattedAddress = '' === $formattedAddress ? null : $formattedAddress;

        return $this;
    }

    public function getLat(): ?float
    {
        return $this->lat;
    }

    public function setLat(?float $lat): static
    {
        $this->lat = $lat;

        return $this;
    }

    public function getLng(): ?float
    {
        return $this->lng;
    }

    public function setLng(?float $lng): static
    {
        $this->lng = $lng;

        return $this;
    }

    public function getPlaceId(): ?string
    {
        return $this->placeId;
    }

    public function setPlaceId(?string $placeId): static
    {
        $placeId = null !== $placeId ? trim($placeId) : null;
        $this->placeId = '' === $placeId ? null : $placeId;

        return $this;
    }

    public function getTravelDurationSeconds(): ?int
    {
        return $this->travelDurationSeconds;
    }

    public function setTravelDurationSeconds(?int $travelDurationSeconds): static
    {
        $this->travelDurationSeconds = $travelDurationSeconds;

        return $this;
    }

    public function getTravelDistanceMeters(): ?int
    {
        return $this->travelDistanceMeters;
    }

    public function setTravelDistanceMeters(?int $travelDistanceMeters): static
    {
        $this->travelDistanceMeters = $travelDistanceMeters;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    /**
     * @param list<string> $products
     */
    public function setProducts(array $products): static
    {
        $normalized = [];
        foreach ($products as $product) {
            if (!\is_string($product)) {
                continue;
            }
            $value = trim($product);
            if ('' !== $value) {
                $normalized[] = $value;
            }
        }
        $this->products = $normalized;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array{
     *     id: int|null,
     *     position: int,
     *     clientName: string,
     *     phone: string|null,
     *     address: string,
     *     addressValid: bool,
     *     formattedAddress: string|null,
     *     lat: float|null,
     *     lng: float|null,
     *     placeId: string|null,
     *     travelDurationSeconds: int|null,
     *     travelDistanceMeters: int|null,
     *     travelDurationText: string|null,
     *     travelDistanceText: string|null,
     *     products: list<string>,
     *     createdAt: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'clientName' => $this->clientName,
            'phone' => $this->phone,
            'address' => $this->address,
            'addressValid' => $this->addressValid,
            'formattedAddress' => $this->formattedAddress,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'placeId' => $this->placeId,
            'travelDurationSeconds' => $this->travelDurationSeconds,
            'travelDistanceMeters' => $this->travelDistanceMeters,
            'travelDurationText' => null !== $this->travelDurationSeconds
                ? self::formatDuration($this->travelDurationSeconds)
                : null,
            'travelDistanceText' => null !== $this->travelDistanceMeters
                ? self::formatDistance($this->travelDistanceMeters)
                : null,
            'products' => $this->products,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }

    private static function formatDistance(int $meters): string
    {
        if ($meters >= 1000) {
            return sprintf('%.1f km', $meters / 1000);
        }

        return sprintf('%d m', $meters);
    }
}
