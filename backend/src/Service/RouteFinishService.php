<?php

namespace App\Service;

use App\Entity\DeliveryRoute;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Marks a route as finished and stores the completion PDF report.
 */
class RouteFinishService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RouteCompletionPdfService $completionPdf,
        private readonly DriverRealtimePublisher $realtime,
        private readonly AppSettingsService $settings,
        private readonly EmailService $emailService,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Finish the route (courier button or auto after the last stop).
     * Generates and stores the completion PDF once.
     */
    public function finish(DeliveryRoute $route): DeliveryRoute
    {
        if (!$route->isStarted()) {
            throw new ConflictHttpException('Route must be started before it can be finished');
        }

        if ($route->isCompleted()) {
            throw new ConflictHttpException('Route is already completed');
        }

        $route->setCompletedAt(new \DateTimeImmutable());
        $stored = $this->storePdf($route);
        $this->maybeEmailCompletionPdf($route, $stored['pdf'], $stored['filename']);

        $this->em->flush();
        $this->realtime->publishRouteCompleted($route);

        return $route;
    }

    /**
     * Re-open a completed route so the courier can continue deliveries.
     */
    public function continueRoute(DeliveryRoute $route): DeliveryRoute
    {
        if (!$route->isStarted()) {
            throw new ConflictHttpException('Route must be started before it can be continued');
        }

        if (!$route->isCompleted()) {
            throw new ConflictHttpException('Route is not completed');
        }

        $pdfPath = $this->absolutePdfPath($route);
        if (null !== $pdfPath && is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        $route->setCompletedAt(null);
        $route->setCompletionPdfPath(null);

        $this->em->flush();
        $this->realtime->publishRouteContinued($route);

        return $route;
    }

    /**
     * Ensure a stored PDF exists for an already completed route (regen / backfill).
     */
    public function ensurePdf(DeliveryRoute $route, bool $force = false): DeliveryRoute
    {
        if (!$route->isCompleted()) {
            throw new ConflictHttpException('Route is not completed');
        }

        if ($force || !$route->hasCompletionPdf() || null === $this->absolutePdfPath($route)) {
            $this->storePdf($route);
            $this->em->flush();
        }

        return $route;
    }

    public function absolutePdfPath(DeliveryRoute $route): ?string
    {
        $relative = $route->getCompletionPdfPath();
        if (null === $relative || '' === $relative) {
            return null;
        }

        $absolute = $this->uploadsRoot().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        if (!is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    /**
     * @return array{pdf: string, filename: string}
     */
    private function storePdf(DeliveryRoute $route): array
    {
        $routeId = $route->getId();
        if (null === $routeId) {
            throw new \RuntimeException('Route is not persisted');
        }

        $pdf = $this->completionPdf->generatePdf($route);
        $filename = $this->completionPdf->suggestedFilename($route);

        $relativeDir = sprintf('route-reports/%d', $routeId);
        $absoluteDir = $this->uploadsRoot().\DIRECTORY_SEPARATOR.$relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Could not create route report directory');
        }

        $absolutePath = $absoluteDir.\DIRECTORY_SEPARATOR.$filename;
        if (false === file_put_contents($absolutePath, $pdf)) {
            throw new \RuntimeException('Failed to store route completion PDF');
        }

        $route->setCompletionPdfPath($relativeDir.'/'.$filename);

        return ['pdf' => $pdf, 'filename' => $filename];
    }

    /**
     * Best-effort: send the completion PDF to the configured settings recipient.
     * Failures must not block route finish.
     */
    private function maybeEmailCompletionPdf(DeliveryRoute $route, string $pdf, string $filename): void
    {
        $to = $this->settings->getPdfReportRecipientEmail();
        if (null === $to) {
            return;
        }

        try {
            $this->emailService->sendRouteCompletionReport(
                $to,
                $pdf,
                $filename,
                $route->getName(),
            );
        } catch (\Throwable) {
            // Keep the route completed even if outbound mail fails.
        }
    }

    private function uploadsRoot(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'uploads';
    }
}
