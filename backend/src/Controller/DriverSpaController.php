<?php

namespace App\Controller;

use App\Service\SpaDistResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves driver_frontend/dist on the driver host (DRIVER_HOST).
 * Hashed JS/CSS are also copied into public/driver-spa/ by the Vite build
 * so nginx can serve them as static files without colliding with admin /spa/.
 */
class DriverSpaController extends AbstractController
{
    public function __construct(
        private readonly SpaDistResponder $spaDistResponder,
    ) {
    }

    #[Route('/', name: 'driver_spa_index', host: '%driver.host%', methods: ['GET'])]
    public function index(): Response
    {
        return $this->spaDistResponder->respond(
            'driver_frontend',
            'index.html',
            true,
            'Driver frontend is not built.',
            'Run: cd driver_frontend && npm install && npm run build',
        );
    }

    #[Route(
        '/{path}',
        name: 'driver_spa_asset',
        host: '%driver.host%',
        requirements: ['path' => '^(?!api(?:/|$)|download(?:/|$)|_profiler|_wdt|bundles).+'],
        methods: ['GET'],
        priority: -100,
    )]
    public function asset(string $path): Response
    {
        return $this->spaDistResponder->respond(
            'driver_frontend',
            $path,
            false,
            'Driver frontend is not built.',
            'Run: cd driver_frontend && npm install && npm run build',
        );
    }
}
