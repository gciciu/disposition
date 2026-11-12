<?php

namespace App\Controller\Api;

use App\Service\DriverAppRelease;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/driver')]
class DriverAppUpdateController extends AbstractController
{
    public function __construct(
        private readonly DriverAppRelease $release,
    ) {
    }

    #[Route('/app-version', name: 'api_driver_app_version', methods: ['GET'])]
    public function version(Request $request): JsonResponse
    {
        return $this->json($this->release->toPublicArray($request));
    }

    #[Route('/download', name: 'api_driver_app_download_page', methods: ['GET'])]
    public function downloadPage(Request $request): Response
    {
        return $this->render('driver/download.html.twig', $this->release->downloadPageVars($request));
    }

    #[Route('/app.apk', name: 'api_driver_app_apk', methods: ['GET'])]
    public function apk(): Response
    {
        return $this->release->apkResponse();
    }
}
