<?php

namespace App\Entity;

use App\Repository\DeliveryRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryRouteRepository::class)]
#[ORM\Table(name: 'delivery_route')]
class DeliveryRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceFilename = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalDurationSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalDistanceMeters = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $mapsUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'routes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Courier $courier = null;

    /** @var Collection<int, DeliveryOrder> */
    #[ORM\OneToMany(targetEntity: DeliveryOrder::class, mappedBy: 'route', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $orders;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->orders = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSourceFilename(): ?string
    {
        return $this->sourceFilename;
    }

    public function setSourceFilename(?string $sourceFilename): static
    {
        $this->sourceFilename = $sourceFilename;

        return $this;
    }

    public function getTotalDurationSeconds(): ?int
    {
        return $this->totalDurationSeconds;
    }

    public function setTotalDurationSeconds(?int $totalDurationSeconds): static
    {
        $this->totalDurationSeconds = $totalDurationSeconds;

        return $this;
    }

    public function getTotalDistanceMeters(): ?int
    {
        return $this->totalDistanceMeters;
    }

    public function setTotalDistanceMeters(?int $totalDistanceMeters): static
    {
        $this->totalDistanceMeters = $totalDistanceMeters;

        return $this;
    }

    public function getMapsUrl(): ?string
    {
        return $this->mapsUrl;
    }

    public function setMapsUrl(?string $mapsUrl): static
    {
        $mapsUrl = null !== $mapsUrl ? trim($mapsUrl) : null;
        $this->mapsUrl = '' === $mapsUrl ? null : $mapsUrl;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCourier(): ?Courier
    {
        return $this->courier;
    }

    public function setCourier(?Courier $courier): static
    {
        $this->courier = $courier;

        return $this;
    }

    /**
     * @return Collection<int, DeliveryOrder>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(DeliveryOrder $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setRoute($this);
        }

        return $this;
    }

    public function removeOrder(DeliveryOrder $order): static
    {
        if ($this->orders->removeElement($order) && $order->getRoute() === $this) {
            $order->setRoute(null);
        }

        return $this;
    }

    /**
     * @return array{
     *     id: int|null,
     *     name: string,
     *     sourceFilename: string|null,
     *     createdAt: string,
     *     orderCount: int,
     *     totalDurationSeconds: int|null,
     *     totalDistanceMeters: int|null,
     *     totalDurationText: string|null,
     *     totalDistanceText: string|null,
     *     mapsUrl: string|null,
     *     courier: array{id: int|null, name: string, login: string}|null
     * }
     */
    public function toListArray(): array
    {
        $courier = $this->courier;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sourceFilename' => $this->sourceFilename,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'orderCount' => $this->orders->count(),
            'totalDurationSeconds' => $this->totalDurationSeconds,
            'totalDistanceMeters' => $this->totalDistanceMeters,
            'totalDurationText' => null !== $this->totalDurationSeconds
                ? self::formatDuration($this->totalDurationSeconds)
                : null,
            'totalDistanceText' => null !== $this->totalDistanceMeters
                ? self::formatDistance($this->totalDistanceMeters)
                : null,
            'mapsUrl' => $this->mapsUrl,
            'courier' => null !== $courier
                ? [
                    'id' => $courier->getId(),
                    'name' => $courier->getUser()?->getName() ?? '',
                    'login' => $courier->getUser()?->getEmail() ?? '',
                ]
                : null,
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     name: string,
     *     sourceFilename: string|null,
     *     createdAt: string,
     *     orderCount: int,
     *     totalDurationSeconds: int|null,
     *     totalDistanceMeters: int|null,
     *     totalDurationText: string|null,
     *     totalDistanceText: string|null,
     *     mapsUrl: string|null,
     *     courier: array{id: int|null, name: string, login: string}|null,
     *     orders: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            ...$this->toListArray(),
            'orders' => array_map(
                static fn (DeliveryOrder $order): array => $order->toArray(),
                $this->orders->toArray(),
            ),
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
