<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'user' => $user->toArray(),
        ]);
    }

    #[Route('/me/password', name: 'api_me_password', methods: ['POST'])]
    #[IsGranted('ROLE_COURIER')]
    public function changePassword(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            if (!$user->hasRole(UserRole::Courier)) {
                return $this->json(['message' => 'Driver access only'], Response::HTTP_FORBIDDEN);
            }

            $payload = $request->toArray();
            $password = $payload['password'] ?? null;
            $confirmation = $payload['password_confirmation'] ?? null;

            if (!\is_string($password) || '' === trim($password)) {
                return $this->json(['message' => 'Please enter a new password'], Response::HTTP_BAD_REQUEST);
            }

            if (!\is_string($confirmation) || $password !== $confirmation) {
                return $this->json(['message' => 'Passwords do not match'], Response::HTTP_BAD_REQUEST);
            }

            if (\strlen($password) < 8) {
                return $this->json(['message' => 'Password must be at least 8 characters'], Response::HTTP_BAD_REQUEST);
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setMustChangePassword(false);
            $this->em->flush();

            return $this->json([
                'user' => $user->toArray(),
            ]);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
