<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves admin_frontend/dist/index.html at /. Hashed JS/CSS are copied into
 * public/spa/ by the Vite build so nginx can serve them as static files.
 */
class AdminSpaController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    #[Route('/', name: 'admin_spa_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->fileFromDist('index.html', true);
    }

    #[Route(
        '/{path}',
        name: 'admin_spa_asset',
        requirements: ['path' => '^(?!api(?:/|$)|_profiler|_wdt|bundles).+'],
        methods: ['GET'],
        priority: -100,
    )]
    public function asset(string $path): Response
    {
        return $this->fileFromDist($path, false);
    }

    private function fileFromDist(string $relative, bool $fallbackToIndex): Response
    {
        $dist = realpath($this->projectDir.'/../admin_frontend/dist');
        if (false === $dist || !is_dir($dist)) {
            return $this->json(
                [
                    'error' => 'Admin frontend is not built.',
                    'hint' => 'Run: cd admin_frontend && npm install && npm run build',
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $normalized = str_replace('\\', '/', $relative);
        $normalized = ltrim($normalized, '/');
        if (str_contains($normalized, '..')) {
            throw $this->createNotFoundException();
        }

        $candidate = $dist.'/'.$normalized;
        $real = is_file($candidate) ? realpath($candidate) : false;
        if (false === $real || !str_starts_with($real, $dist.DIRECTORY_SEPARATOR)) {
            if ($fallbackToIndex) {
                $index = $dist.'/index.html';
                if (is_file($index)) {
                    return $this->htmlFile($index);
                }
            }
            throw $this->createNotFoundException();
        }

        if (str_ends_with(strtolower($real), '.html')) {
            return $this->htmlFile($real);
        }

        $response = new BinaryFileResponse($real);
        $mime = MimeTypes::getDefault()->guessMimeType($real);
        if (null !== $mime) {
            $response->headers->set('Content-Type', $mime);
        }
        $response->setPublic();
        $response->setMaxAge(86_400);

        return $response;
    }

    private function htmlFile(string $path): Response
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, private');

        return $response;
    }
}
