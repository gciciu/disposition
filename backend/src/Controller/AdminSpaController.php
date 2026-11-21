<?php

namespace App\Controller;

use App\Service\SpaDistResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves admin_frontend/dist on the admin host (ADMIN_HOST).
 * Hashed JS/CSS are also copied into public/spa/ by the Vite build
 * so nginx can serve them as static files.
 */
class AdminSpaController extends AbstractController
{
    public function __construct(
        private readonly SpaDistResponder $spaDistResponder,
    ) {
    }

    #[Route('/', name: 'admin_spa_index', host: '%admin.host%', methods: ['GET'])]
    public function index(): Response
    {
        return $this->spaDistResponder->respond(
            'admin_frontend',
            'index.html',
            true,
            'Admin frontend is not built.',
            'Run: cd admin_frontend && npm install && npm run build',
        );
    }

    #[Route(
        '/{path}',
        name: 'admin_spa_asset',
        host: '%admin.host%',
        requirements: ['path' => '^(?!api(?:/|$)|download(?:/|$)|_profiler|_wdt|bundles).+'],
        methods: ['GET'],
        priority: -100,
    )]
    public function asset(string $path): Response
    {
        return $this->spaDistResponder->respond(
            'admin_frontend',
            $path,
            false,
            'Admin frontend is not built.',
            'Run: cd admin_frontend && npm install && npm run build',
        );
    }
}
