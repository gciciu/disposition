<?php

namespace App\Controller;

use App\Service\DriverAppRelease;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Friendly public URLs for installing the driver Android app.
 * Mirror under /api/driver/download for the driver host (API already proxied).
 */
class DriverAppDownloadController extends AbstractController
{
    public function __construct(
        private readonly DriverAppRelease $release,
    ) {
    }

    #[Route('/download', name: 'driver_app_download_page', methods: ['GET'], priority: 10)]
    public function page(Request $request): Response
    {
        return $this->render('driver/download.html.twig', $this->release->downloadPageVars($request));
    }

    #[Route('/download/app.apk', name: 'driver_app_download_apk', methods: ['GET'], priority: 10)]
    public function apk(): Response
    {
        return $this->release->apkResponse();
    }
}
