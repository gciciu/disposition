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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
     *     orderCount: int
     * }
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sourceFilename' => $this->sourceFilename,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'orderCount' => $this->orders->count(),
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     name: string,
     *     sourceFilename: string|null,
     *     createdAt: string,
     *     orderCount: int,
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
}
