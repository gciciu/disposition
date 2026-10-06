<?php

namespace App\Entity;

use App\Repository\CourierRepository;
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

    public function canDelete(): bool
    {
        // Placeholder for future relations (routes, orders, etc.)
        return true;
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
