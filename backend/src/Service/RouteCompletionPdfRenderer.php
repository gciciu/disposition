<?php

namespace App\Service;

use App\Dto\RouteCompletionPdf\RouteCompletionPdfDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Twig\Environment;

/**
 * Renders {@see RouteCompletionPdfDocument} to HTML and PDF bytes.
 *
 * Page 1 (cover/map) = A4 portrait; following pages = A4 landscape.
 * Dompdf cannot mix orientations in one document, so cover and body are
 * rendered separately and merged with FPDI.
 */
class RouteCompletionPdfRenderer
{
    private const TEMPLATE = 'pdf/route_completion.html.twig';

    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function renderHtml(RouteCompletionPdfDocument $document): string
    {
        return $this->twig->render(self::TEMPLATE, $this->templateVars($document, 'all'));
    }

    public function renderPdf(RouteCompletionPdfDocument $document): string
    {
        $coverPdf = $this->renderPartPdf($document, 'cover', 'portrait');
        $bodyPdf = $this->renderPartPdf($document, 'body', 'landscape');

        return $this->mergePdfs([$coverPdf, $bodyPdf]);
    }

    public function suggestedFilename(RouteCompletionPdfDocument $document, ?int $routeId = null): string
    {
        $date = preg_replace('/[^\d.\-]/', '', $document->header->reportDate) ?: 'report';
        $idPart = null !== $routeId ? $routeId.'_' : '';

        return sprintf('route_full_%s%s.pdf', $idPart, $date);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateVars(RouteCompletionPdfDocument $document, string $part): array
    {
        return [
            'doc' => $document,
            'header' => $document->header,
            'stops' => $document->stops,
            'accessories' => $document->accessories,
            'returns' => $document->returns,
            'footerLabel' => $document->footerLabel,
            'part' => $part,
        ];
    }

    private function renderPartPdf(RouteCompletionPdfDocument $document, string $part, string $orientation): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot(\dirname(__DIR__, 2));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render(self::TEMPLATE, $this->templateVars($document, $part)), 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param list<string> $pdfBinaries
     */
    private function mergePdfs(array $pdfBinaries): string
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);

        foreach ($pdfBinaries as $binary) {
            if ('' === $binary) {
                continue;
            }

            $pageCount = $pdf->setSourceFile(StreamReader::createByString($binary));
            for ($pageNo = 1; $pageNo <= $pageCount; ++$pageNo) {
                $tpl = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }

        return $pdf->Output('S');
    }
}
