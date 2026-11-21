<?php

namespace App\Controller\Api;

use App\Service\AppSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
class AppSettingsController extends AbstractController
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
    ) {
    }

    #[Route('', name: 'api_admin_settings_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(['settings' => $this->settingsService->get()->toArray()]);
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        try {
            $settings = $this->settingsService->update($request->toArray());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['settings' => $settings->toArray()]);
    }
}
