<?php

namespace App\Service;

use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfDocument;
use App\Entity\DeliveryOrder;

/**
 * Facade: build Lieferbestätigung from an order and render HTML/PDF.
 *
 * Later (email send):
 *   $pdf = $this->confirmationPdf->generatePdf($order);
 *   // attach $pdf to mail…
 */
class DeliveryConfirmationPdfService
{
    public function __construct(
        private readonly DeliveryConfirmationPdfBuilder $builder,
        private readonly DeliveryConfirmationPdfRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides See {@see DeliveryConfirmationPdfBuilder::build()}
     */
    public function buildDocument(DeliveryOrder $order, array $overrides = []): DeliveryConfirmationPdfDocument
    {
        return $this->builder->build($order, $overrides);
    }

    public function buildSampleDocument(?string $customerEmail = null): DeliveryConfirmationPdfDocument
    {
        return $this->builder->buildSample($customerEmail);
    }

    /**
     * @return array{document: DeliveryConfirmationPdfDocument, pdf: string, filename: string}
     */
    public function generateSamplePdf(?string $customerEmail = null): array
    {
        $document = $this->buildSampleDocument($customerEmail);

        return [
            'document' => $document,
            'pdf' => $this->renderer->renderPdf($document),
            'filename' => $this->renderer->suggestedFilename($document),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function generateHtml(DeliveryOrder $order, array $overrides = []): string
    {
        return $this->renderer->renderHtml($this->buildDocument($order, $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function generatePdf(DeliveryOrder $order, array $overrides = []): string
    {
        return $this->renderer->renderPdf($this->buildDocument($order, $overrides));
    }

    public function suggestedFilename(DeliveryOrder $order, ?DeliveryConfirmationPdfDocument $document = null): string
    {
        $document ??= $this->buildDocument($order);

        return $this->renderer->suggestedFilename($document, $order->getId());
    }
}
