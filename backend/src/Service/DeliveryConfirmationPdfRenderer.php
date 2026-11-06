<?php

namespace App\Service;

use App\Dto\DeliveryConfirmationPdf\DeliveryConfirmationPdfDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders {@see DeliveryConfirmationPdfDocument} to HTML and PDF bytes.
 */
class DeliveryConfirmationPdfRenderer
{
    private const TEMPLATE = 'pdf/delivery_confirmation.html.twig';

    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function renderHtml(DeliveryConfirmationPdfDocument $document): string
    {
        return $this->twig->render(self::TEMPLATE, [
            'doc' => $document,
            'company' => $document->company,
        ]);
    }

    public function renderPdf(DeliveryConfirmationPdfDocument $document): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot(\dirname(__DIR__, 2));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderHtml($document), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function suggestedFilename(DeliveryConfirmationPdfDocument $document, ?int $orderId = null): string
    {
        $routeSlug = preg_replace('/[^\p{L}\p{N}\-_]+/u', '_', $document->routeLabel) ?: 'route';
        $routeSlug = trim((string) $routeSlug, '_');
        $idPart = null !== $orderId ? $orderId.'_' : '';

        return sprintf(
            'lieferbestaetigung_%spos%d_%s.pdf',
            $idPart,
            $document->position,
            $routeSlug,
        );
    }
}
