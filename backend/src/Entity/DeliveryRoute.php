<?php

namespace App\Entity;

use App\Enum\DeliveryOrderStatus;
use App\Enum\RouteOriginType;
use App\Repository\DeliveryRouteRepository;
use App\Util\AddressFormatter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryRouteRepository::class)]
#[ORM\Table(name: 'delivery_route')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_route_courier_scheduled_date', columns: ['courier_id', 'scheduled_date'])]
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Warehouse $warehouse = null;

    #[ORM\Column(length: 32, enumType: RouteOriginType::class)]
    private RouteOriginType $originType = RouteOriginType::Warehouse;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Relative path under var/uploads for the generated completion PDF. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $completionPdfPath = null;

    #[ORM\Column(nullable: true)]
    private ?float $startLat = null;

    #[ORM\Column(nullable: true)]
    private ?float $startLng = null;

    /** Planned delivery day (Mon–Fri). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledDate = null;

    /** Planned start time of day (optional). */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledStartTime = null;

    /** Total cargo weight in kg from the import PDF. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $totalWeightKg = null;

    /** Cumulative courier-reported delay in minutes (shifts all planned arrivals). */
    #[ORM\Column(options: ['default' => 0])]
    private int $delayMinutes = 0;

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

    public function getWarehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function setWarehouse(?Warehouse $warehouse): static
    {
        $this->warehouse = $warehouse;
        if (null !== $warehouse) {
            $this->originType = RouteOriginType::Warehouse;
        } elseif (RouteOriginType::Warehouse === $this->originType) {
            $this->originType = RouteOriginType::None;
        }

        return $this;
    }

    public function getOriginType(): RouteOriginType
    {
        return $this->originType;
    }

    public function setOriginType(RouteOriginType $originType): static
    {
        $this->originType = $originType;
        if (RouteOriginType::Warehouse !== $originType) {
            $this->warehouse = null;
        }

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function isStarted(): bool
    {
        return null !== $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedAt;
    }

    public function getCompletionPdfPath(): ?string
    {
        return $this->completionPdfPath;
    }

    public function setCompletionPdfPath(?string $completionPdfPath): static
    {
        $completionPdfPath = null !== $completionPdfPath ? trim($completionPdfPath) : null;
        $this->completionPdfPath = '' === $completionPdfPath ? null : $completionPdfPath;

        return $this;
    }

    public function hasCompletionPdf(): bool
    {
        return null !== $this->completionPdfPath && '' !== $this->completionPdfPath;
    }

    public function getStartLat(): ?float
    {
        return $this->startLat;
    }

    public function getStartLng(): ?float
    {
        return $this->startLng;
    }

    public function setStartCoordinates(?float $lat, ?float $lng): static
    {
        $this->startLat = $lat;
        $this->startLng = $lng;

        return $this;
    }

    public function getScheduledDate(): ?\DateTimeImmutable
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(?\DateTimeImmutable $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;

        return $this;
    }

    public function getScheduledStartTime(): ?\DateTimeImmutable
    {
        return $this->scheduledStartTime;
    }

    public function setScheduledStartTime(?\DateTimeImmutable $scheduledStartTime): static
    {
        $this->scheduledStartTime = $scheduledStartTime;

        return $this;
    }

    public function getTotalWeightKg(): ?float
    {
        return $this->totalWeightKg;
    }

    public function setTotalWeightKg(?float $totalWeightKg): static
    {
        $this->totalWeightKg = $totalWeightKg;

        return $this;
    }

    public function getDelayMinutes(): int
    {
        return $this->delayMinutes;
    }

    public function setDelayMinutes(int $delayMinutes): static
    {
        $this->delayMinutes = max(0, $delayMinutes);

        return $this;
    }

    public function addDelayMinutes(int $minutes): static
    {
        if ($minutes > 0) {
            $this->delayMinutes += $minutes;
        }

        return $this;
    }

    /**
     * True when every stop is delivered or refused (ready to finish / auto-finish).
     */
    public function areAllOrdersFinal(): bool
    {
        if ($this->orders->isEmpty()) {
            return false;
        }

        foreach ($this->orders as $order) {
            if (!$order->getStatus()->isFinal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @deprecated Use {@see areAllOrdersFinal()} + {@see \App\Service\RouteFinishService::finish()}
     * Kept as a no-op alias so older call sites do not silently mark the route done without a PDF.
     */
    public function syncCompletionFromOrders(): static
    {
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
     *     deliveredCount: int,
     *     progress: int,
     *     totalDurationSeconds: int|null,
     *     totalDistanceMeters: int|null,
     *     totalDurationText: string|null,
     *     totalDistanceText: string|null,
     *     mapsUrl: string|null,
     *     courier: array{id: int|null, name: string, login: string}|null,
     *     originType: string,
     *     warehouse: array{id: int|null, name: string, address: string}|null,
     *     startedAt: string|null,
     *     completedAt: string|null,
     *     hasCompletionPdf: bool,
     *     allOrdersFinal: bool
     * }
     */
    public function toListArray(): array
    {
        $courier = $this->courier;
        $warehouse = $this->warehouse;
        $orderCount = $this->orders->count();
        $deliveredCount = 0;
        $completedCount = 0;
        foreach ($this->orders as $order) {
            $status = $order->getStatus();
            if (DeliveryOrderStatus::Delivered === $status) {
                ++$deliveredCount;
            }
            if ($status->isFinal()) {
                ++$completedCount;
            }
        }
        $progress = $orderCount > 0 ? (int) round(($completedCount / $orderCount) * 100) : 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sourceFilename' => $this->sourceFilename,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'scheduledDate' => $this->scheduledDate?->format('Y-m-d'),
            'scheduledStartTime' => $this->scheduledStartTime?->format('H:i'),
            'orderCount' => $orderCount,
            'deliveredCount' => $deliveredCount,
            'completedCount' => $completedCount,
            'progress' => $progress,
            'totalWeightKg' => $this->totalWeightKg,
            'totalWeightText' => null !== $this->totalWeightKg
                ? self::formatWeight($this->totalWeightKg)
                : null,
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
                    'phone' => $courier->getPhone(),
                ]
                : null,
            'subcontractor' => $courier?->getSubcontractor(),
            'originType' => $this->originType->value,
            'warehouse' => null !== $warehouse
                ? [
                    'id' => $warehouse->getId(),
                    'name' => $warehouse->getName(),
                    'address' => AddressFormatter::withoutCountry(
                        $warehouse->getFormattedAddress() ?? $warehouse->getAddress(),
                    ),
                ]
                : null,
            'startedAt' => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'completedAt' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'hasCompletionPdf' => $this->hasCompletionPdf(),
            'allOrdersFinal' => $this->areAllOrdersFinal(),
            'delayMinutes' => $this->delayMinutes,
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
     *     originType: string,
     *     warehouse: array{id: int|null, name: string, address: string}|null,
     *     orders: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        $arrivalTimes = $this->computeArrivalTimes();

        return [
            ...$this->toListArray(),
            'orders' => array_map(
                static fn (DeliveryOrder $order): array => $order->toArray(
                    $arrivalTimes[$order->getId()] ?? null,
                ),
                $this->orders->toArray(),
            ),
        ];
    }

    /**
     * Planned arrival clock times (H:i) keyed by order id.
     *
     * Before any stop is finished:
     *   arrival(1) = scheduledStartTime + delay + travel_to(1)
     *   arrival(n) = arrival(n-1) + unload(n-1) + travel_to(n)
     *
     * After a stop is delivered/refused, remaining arrivals are rebased from that
     * stop's completedAt (leave time), then travel + planned unload cascade again.
     *
     * @return array<int, string|null>
     */
    public function computeArrivalTimes(): array
    {
        $result = [];
        $orders = array_values($this->orders->toArray());

        $cursorSeconds = null;
        if (null !== $this->scheduledStartTime) {
            $cursorSeconds = ((int) $this->scheduledStartTime->format('H')) * 3600
                + ((int) $this->scheduledStartTime->format('i')) * 60
                + ($this->delayMinutes * 60);
        }

        foreach ($orders as $order) {
            $orderId = $order->getId();
            $isFinished = $order->getStatus()->isFinal() && null !== $order->getCompletedAt();

            if ($isFinished) {
                if (null !== $cursorSeconds) {
                    $cursorSeconds += $order->getTravelDurationSeconds() ?? 0;
                    if (null !== $orderId) {
                        $result[$orderId] = self::formatClockTime($cursorSeconds);
                    }
                } elseif (null !== $orderId) {
                    $result[$orderId] = $order->getCompletedAt()->format('H:i');
                }

                // Courier is free to leave at the actual completion time.
                $cursorSeconds = self::secondsFromMidnight($order->getCompletedAt());
                continue;
            }

            if (null === $cursorSeconds) {
                if (null !== $orderId) {
                    $result[$orderId] = null;
                }
                continue;
            }

            $cursorSeconds += $order->getTravelDurationSeconds() ?? 0;
            if (null !== $orderId) {
                $result[$orderId] = self::formatClockTime($cursorSeconds);
            }
            $cursorSeconds += $order->getUnloadingDurationMinutes() * 60;
        }

        return $result;
    }

    private static function secondsFromMidnight(\DateTimeImmutable $time): int
    {
        return ((int) $time->format('H')) * 3600
            + ((int) $time->format('i')) * 60
            + ((int) $time->format('s'));
    }

    private static function formatClockTime(int $secondsFromMidnight): string
    {
        // Allow schedules that spill past midnight (e.g. late evening routes).
        $normalized = $secondsFromMidnight % 86400;
        if ($normalized < 0) {
            $normalized += 86400;
        }

        $hours = intdiv($normalized, 3600);
        $minutes = intdiv($normalized % 3600, 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    public static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }

    public static function formatDistance(int $meters): string
    {
        if ($meters >= 1000) {
            return sprintf('%.1f km', $meters / 1000);
        }

        return sprintf('%d m', $meters);
    }

    public static function formatWeight(float $kg): string
    {
        return number_format($kg, 1, ',', '').' KG';
    }
}
