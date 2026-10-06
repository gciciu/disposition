<?php

namespace App\Controller\Api;

use App\Service\RefreshTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/token')]
class TokenController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
    ) {
    }

    #[Route('/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $this->readRefreshToken($request);
        $payload = null !== $refreshToken ? $this->refreshTokenService->refresh($refreshToken) : null;

        if (null === $payload) {
            return $this->json([
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'Сессия истекла',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($payload);
    }

    #[Route('/logout', name: 'api_token_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $refreshToken = $this->readRefreshToken($request);
        if (null !== $refreshToken) {
            $this->refreshTokenService->revoke($refreshToken);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function readRefreshToken(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !isset($payload['refresh_token']) || !\is_string($payload['refresh_token'])) {
            return null;
        }

        $refreshToken = trim($payload['refresh_token']);

        return '' === $refreshToken ? null : $refreshToken;
    }
}
