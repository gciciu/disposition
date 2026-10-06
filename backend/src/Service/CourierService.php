<?php

namespace App\Service;

use App\Entity\Courier;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\CourierRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CourierService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourierRepository $courierRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @return list<Courier>
     */
    public function list(): array
    {
        return $this->courierRepository->findAllOrderedByName();
    }

    /**
     * @param array{name?: mixed, login?: mixed, password?: mixed} $data
     */
    public function create(array $data): Courier
    {
        $name = $this->requireNonEmptyString($data, 'name');
        $login = $this->normalizeLogin($this->requireNonEmptyString($data, 'login'));
        $password = $this->requireNonEmptyString($data, 'password');

        $this->assertLoginAvailable($login);

        $user = (new User())
            ->setName($name)
            ->setEmail($login)
            ->setRoles([UserRole::Courier->value])
            ->setIsActive(true);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $courier = (new Courier())->setUser($user);
        $user->setCourier($courier);

        $this->em->persist($courier);
        $this->em->flush();

        return $courier;
    }

    /**
     * @param array{name?: mixed, login?: mixed, password?: mixed} $data
     */
    public function update(Courier $courier, array $data): Courier
    {
        $user = $courier->getUser();
        if (null === $user) {
            throw new BadRequestHttpException('Courier has no linked user');
        }

        if (\array_key_exists('name', $data)) {
            $user->setName($this->requireNonEmptyString($data, 'name'));
        }

        if (\array_key_exists('login', $data)) {
            $login = $this->normalizeLogin($this->requireNonEmptyString($data, 'login'));
            if ($login !== $user->getEmail()) {
                $this->assertLoginAvailable($login, $user);
                $user->setEmail($login);
            }
        }

        if (\array_key_exists('password', $data)) {
            $password = $data['password'];
            if (null !== $password && '' !== $password) {
                if (!\is_string($password)) {
                    throw new BadRequestHttpException('password must be a string');
                }
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            }
        }

        $this->em->flush();

        return $courier;
    }

    public function delete(Courier $courier): void
    {
        if (!$courier->canDelete()) {
            throw new ConflictHttpException('Courier cannot be deleted because it is linked to other records');
        }

        $user = $courier->getUser();

        $this->em->remove($courier);
        if (null !== $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    private function assertLoginAvailable(string $login, ?User $except = null): void
    {
        $existing = $this->userRepository->findOneBy(['email' => $login]);
        if (null === $existing) {
            return;
        }

        if (null !== $except && $existing->getId() === $except->getId()) {
            return;
        }

        throw new ConflictHttpException('A user with this login already exists');
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

    private function normalizeLogin(string $login): string
    {
        $login = strtolower(trim($login));
        if (!filter_var($login, \FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('login must be a valid email');
        }

        return $login;
    }
}
