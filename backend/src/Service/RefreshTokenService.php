<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RefreshTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
        #[Autowire('%env(int:REFRESH_TOKEN_TTL)%')]
        private readonly int $ttl,
        #[Autowire('%env(int:REFRESH_TOKEN_TTL_SESSION)%')]
        private readonly int $sessionTtl,
    ) {
    }

    public function issue(User $user, bool $remember): string
    {
        $this->refreshTokenRepository->deleteExpired();

        $raw = $this->persistToken($user, $remember);
        $this->em->flush();

        return $raw;
    }

    /**
     * @return array{token: string, refresh_token: string, user: array{
     *     id: int|null,
     *     email: string,
     *     name: string,
     *     roles: list<string>,
     *     isActive: bool,
     *     mustChangePassword: bool
     * }}|null
     */
    public function refresh(string $rawToken): ?array
    {
        $existing = $this->findUsable($rawToken);
        if (null === $existing) {
            return null;
        }

        $user = $existing->getUser();
        $remember = $existing->isRemember();
        $this->em->remove($existing);

        $raw = $this->persistToken($user, $remember);
        $this->em->flush();

        return [
            'token' => $this->jwtManager->create($user),
            'refresh_token' => $raw,
            'user' => $user->toArray(),
        ];
    }

    public function revoke(string $rawToken): void
    {
        if ('' === $rawToken) {
            return;
        }

        $existing = $this->refreshTokenRepository->findOneByTokenHash(hash('sha256', $rawToken));
        if (null === $existing) {
            return;
        }

        $this->em->remove($existing);
        $this->em->flush();
    }

    private function findUsable(string $rawToken): ?RefreshToken
    {
        if ('' === $rawToken) {
            return null;
        }

        $existing = $this->refreshTokenRepository->findOneByTokenHash(hash('sha256', $rawToken));
        if (null === $existing) {
            return null;
        }

        if ($existing->isExpired() || !$existing->getUser()->isActive()) {
            $this->em->remove($existing);
            $this->em->flush();

            return null;
        }

        return $existing;
    }

    private function persistToken(User $user, bool $remember): string
    {
        $raw = bin2hex(random_bytes(32));
        $ttl = $remember ? $this->ttl : $this->sessionTtl;
        $token = new RefreshToken(
            $user,
            hash('sha256', $raw),
            $remember,
            new \DateTimeImmutable(sprintf('+%d seconds', $ttl)),
        );
        $this->em->persist($token);

        return $raw;
    }
}
