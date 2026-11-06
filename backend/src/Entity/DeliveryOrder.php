<?php

namespace App\Entity;

use App\Enum\DeliveryOrderStatus;
use App\Enum\DeliveryProofType;
use App\Repository\DeliveryOrderRepository;
use App\Util\AddressFormatter;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeliveryOrderRepository::class)]
#[ORM\Table(name: 'delivery_order')]
class DeliveryOrder
{
    public const UNLOADING_DURATION_MIN = 1;

    public const UNLOADING_DURATION_MAX = 60;

    public const UNLOADING_DURATION_DEFAULT = 10;

    /** @var list<string> */
    public const COMMENT_COLORS = ['amber', 'red', 'green', 'blue', 'orange', 'gray'];

    public const COMMENT_COLOR_DEFAULT = 'amber';

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

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

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

    /** Stop cargo weight in kg from the import PDF, if present. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $weightKg = null;

    /** Planned unloading / service time at this stop (minutes). */
    #[ORM\Column(options: ['default' => 10])]
    private int $unloadingDurationMinutes = self::UNLOADING_DURATION_DEFAULT;

    /**
     * Colored remark list, e.g. [{"id":"…","text":"Tel. Avis. 1 Tag vorher","color":"amber"}].
     *
     * @var list<array{id: string, text: string, color: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $comments = [];

    /**
     * Product lines, e.g. ["1 x HZ BS Weiß / 19 mm, 120 x 60 cm / Weiß"].
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $products = [];

    /**
     * Background colors from the PDF article cell, parallel to $products (e.g. "#99CCFF").
     *
     * @var list<string|null>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $productBackgroundColors = null;

    /**
     * Indexes into $products that were delivered. Null until the stop is completed.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $deliveredProductIndexes = null;

    #[ORM\Column(length: 32, enumType: DeliveryOrderStatus::class)]
    private DeliveryOrderStatus $status = DeliveryOrderStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(length: 32, nullable: true, enumType: DeliveryProofType::class)]
    private ?DeliveryProofType $proofType = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $proofPath = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $proofMime = null;

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = null !== $email ? trim($email) : null;
        $this->email = '' === $email ? null : $email;

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

    public function getWeightKg(): ?float
    {
        return $this->weightKg;
    }

    public function setWeightKg(?float $weightKg): static
    {
        $this->weightKg = $weightKg;

        return $this;
    }

    public function getUnloadingDurationMinutes(): int
    {
        return $this->unloadingDurationMinutes;
    }

    public function setUnloadingDurationMinutes(int $unloadingDurationMinutes): static
    {
        if (
            $unloadingDurationMinutes < self::UNLOADING_DURATION_MIN
            || $unloadingDurationMinutes > self::UNLOADING_DURATION_MAX
        ) {
            throw new \InvalidArgumentException(sprintf(
                'unloadingDurationMinutes must be between %d and %d',
                self::UNLOADING_DURATION_MIN,
                self::UNLOADING_DURATION_MAX,
            ));
        }

        $this->unloadingDurationMinutes = $unloadingDurationMinutes;

        return $this;
    }

    /**
     * @return list<array{id: string, text: string, color: string}>
     */
    public function getComments(): array
    {
        return $this->comments;
    }

    /**
     * @param list<array{id?: mixed, text?: mixed, color?: mixed}> $comments
     */
    public function setComments(array $comments): static
    {
        $normalized = [];
        foreach ($comments as $comment) {
            if (!\is_array($comment)) {
                continue;
            }
            $text = isset($comment['text']) && \is_string($comment['text'])
                ? trim($comment['text'])
                : '';
            if ('' === $text) {
                continue;
            }

            $id = isset($comment['id']) && \is_string($comment['id'])
                ? trim($comment['id'])
                : '';
            if ('' === $id) {
                $id = self::newCommentId();
            }

            $color = isset($comment['color']) && \is_string($comment['color'])
                ? trim($comment['color'])
                : self::COMMENT_COLOR_DEFAULT;
            if (!\in_array($color, self::COMMENT_COLORS, true)) {
                $color = self::COMMENT_COLOR_DEFAULT;
            }

            $normalized[] = [
                'id' => $id,
                'text' => $text,
                'color' => $color,
            ];
        }
        $this->comments = $normalized;

        return $this;
    }

    public function getCommentsText(): ?string
    {
        $parts = [];
        foreach ($this->comments as $comment) {
            $text = trim((string) ($comment['text'] ?? ''));
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        if ([] === $parts) {
            return null;
        }

        return implode(' ', $parts);
    }

    public static function newCommentId(): string
    {
        return bin2hex(random_bytes(16));
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

    /**
     * @return list<string|null>|null
     */
    public function getProductBackgroundColors(): ?array
    {
        return $this->productBackgroundColors;
    }

    /**
     * @param list<string|null>|null $productBackgroundColors
     */
    public function setProductBackgroundColors(?array $productBackgroundColors): static
    {
        if (null === $productBackgroundColors) {
            $this->productBackgroundColors = null;

            return $this;
        }

        $normalized = [];
        foreach ($productBackgroundColors as $color) {
            if (null === $color) {
                $normalized[] = null;
                continue;
            }
            if (!\is_string($color)) {
                $normalized[] = null;
                continue;
            }
            $value = strtoupper(trim($color));
            if (1 !== preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $normalized[] = null;
                continue;
            }
            $normalized[] = $value;
        }

        $this->productBackgroundColors = $normalized;

        return $this;
    }

    /**
     * @return list<int>|null
     */
    public function getDeliveredProductIndexes(): ?array
    {
        return $this->deliveredProductIndexes;
    }

    /**
     * @param list<int>|null $deliveredProductIndexes
     */
    public function setDeliveredProductIndexes(?array $deliveredProductIndexes): static
    {
        if (null === $deliveredProductIndexes) {
            $this->deliveredProductIndexes = null;

            return $this;
        }

        $normalized = [];
        foreach ($deliveredProductIndexes as $index) {
            if (!\is_int($index) && !(\is_string($index) && ctype_digit($index))) {
                continue;
            }
            $value = (int) $index;
            if ($value < 0 || !\array_key_exists($value, $this->products)) {
                continue;
            }
            $normalized[$value] = $value;
        }

        $this->deliveredProductIndexes = array_values($normalized);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): DeliveryOrderStatus
    {
        return $this->status;
    }

    public function setStatus(DeliveryOrderStatus $status): static
    {
        $this->status = $status;

        return $this;
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

    public function getProofType(): ?DeliveryProofType
    {
        return $this->proofType;
    }

    public function setProofType(?DeliveryProofType $proofType): static
    {
        $this->proofType = $proofType;

        return $this;
    }

    public function getProofPath(): ?string
    {
        return $this->proofPath;
    }

    public function setProofPath(?string $proofPath): static
    {
        $this->proofPath = $proofPath;

        return $this;
    }

    public function getProofMime(): ?string
    {
        return $this->proofMime;
    }

    public function setProofMime(?string $proofMime): static
    {
        $this->proofMime = $proofMime;

        return $this;
    }

    public function hasProof(): bool
    {
        return null !== $this->proofPath && '' !== $this->proofPath;
    }

    /**
     * @return array{
     *     id: int|null,
     *     position: int,
     *     clientName: string,
     *     phone: string|null,
     *     email: string|null,
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
     *     weightKg: float|null,
     *     unloadingDurationMinutes: int,
     *     arrivalTime: string|null,
     *     comments: list<array{id: string, text: string, color: string}>,
     *     products: list<string>,
     *     productBackgroundColors: list<string|null>|null,
     *     deliveredProductIndexes: list<int>|null,
     *     status: string,
     *     completedAt: string|null,
     *     proofType: string|null,
     *     hasProof: bool,
     *     createdAt: string
     * }
     */
    public function toArray(?string $arrivalTime = null): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'clientName' => $this->clientName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => AddressFormatter::withoutCountry($this->address) ?? $this->address,
            'addressValid' => $this->addressValid,
            'formattedAddress' => AddressFormatter::withoutCountry($this->formattedAddress),
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
            'weightKg' => $this->weightKg,
            'unloadingDurationMinutes' => $this->unloadingDurationMinutes,
            'arrivalTime' => $arrivalTime,
            'comments' => $this->comments,
            'products' => $this->products,
            'productBackgroundColors' => $this->productBackgroundColors,
            'deliveredProductIndexes' => $this->deliveredProductIndexes,
            'status' => $this->status->value,
            'completedAt' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'proofType' => $this->proofType?->value,
            'hasProof' => $this->hasProof(),
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
