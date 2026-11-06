<?php

namespace App\Service;

use App\Dto\RouteCompletionPdf\RouteCompletionPdfDocument;
use App\Entity\DeliveryRoute;

/**
 * Facade: build document from a completed route and render HTML/PDF.
 *
 * Usage later from a controller:
 *   $pdf = $this->routeCompletionPdf->generatePdf($route);
 *   return new Response($pdf, 200, ['Content-Type' => 'application/pdf', ...]);
 */
class RouteCompletionPdfService
{
    public function __construct(
        private readonly RouteCompletionPdfBuilder $builder,
        private readonly RouteCompletionPdfRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides See {@see RouteCompletionPdfBuilder::build()}
     */
    public function buildDocument(DeliveryRoute $route, array $overrides = []): RouteCompletionPdfDocument
    {
        return $this->builder->build($route, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function generateHtml(DeliveryRoute $route, array $overrides = []): string
    {
        return $this->renderer->renderHtml($this->buildDocument($route, $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function generatePdf(DeliveryRoute $route, array $overrides = []): string
    {
        return $this->renderer->renderPdf($this->buildDocument($route, $overrides));
    }

    public function suggestedFilename(DeliveryRoute $route, ?RouteCompletionPdfDocument $document = null): string
    {
        $document ??= $this->buildDocument($route);

        return $this->renderer->suggestedFilename($document, $route->getId());
    }
}
