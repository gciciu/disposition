<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serves a Vite SPA from a sibling frontend dist/ directory
 * (admin_frontend or driver_frontend).
 */
final class SpaDistResponder
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function respond(
        string $frontendDirName,
        string $relative,
        bool $fallbackToIndex,
        string $notBuiltError,
        string $buildHint,
    ): Response {
        $dist = realpath($this->projectDir.'/../'.$frontendDirName.'/dist');
        if (false === $dist || !is_dir($dist)) {
            return new JsonResponse(
                [
                    'error' => $notBuiltError,
                    'hint' => $buildHint,
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $normalized = str_replace('\\', '/', $relative);
        $normalized = ltrim($normalized, '/');
        if (str_contains($normalized, '..')) {
            throw new NotFoundHttpException();
        }

        $candidate = $dist.'/'.$normalized;
        $real = is_file($candidate) ? realpath($candidate) : false;
        if (false === $real || !str_starts_with($real, $dist.\DIRECTORY_SEPARATOR)) {
            if ($fallbackToIndex) {
                $index = $dist.'/index.html';
                if (is_file($index)) {
                    return $this->htmlFile($index);
                }
            }

            throw new NotFoundHttpException();
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
