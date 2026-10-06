<?php

namespace App\Entity;

use App\Repository\CourierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourierRepository::class)]
#[ORM\Table(name: 'courier')]
class Courier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'courier', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** @var Collection<int, DeliveryRoute> */
    #[ORM\OneToMany(targetEntity: DeliveryRoute::class, mappedBy: 'courier')]
    private Collection $routes;

    public function __construct()
    {
        $this->routes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, DeliveryRoute>
     */
    public function getRoutes(): Collection
    {
        return $this->routes;
    }

    public function canDelete(): bool
    {
        return $this->routes->isEmpty();
    }

    /**
     * @return array{
     *     id: int|null,
     *     name: string,
     *     login: string,
     *     isActive: bool,
     *     canDelete: bool
     * }
     */
    public function toArray(): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'name' => $user?->getName() ?? '',
            'login' => $user?->getEmail() ?? '',
            'isActive' => $user?->isActive() ?? false,
            'canDelete' => $this->canDelete(),
        ];
    }
}
