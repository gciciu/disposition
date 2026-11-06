<?php

namespace App\Dto\RouteCompletionPdf;

/**
 * Cover / header block of the route completion PDF
 * (title row, from→to line, optional overview map).
 */
final class RouteCompletionPdfHeader
{
    public function __construct(
        /** Route title, e.g. "KW 26/35 F. Napolitano 967 KG 880 KM". */
        public readonly string $title,
        public readonly string $driverName,
        /** Total cargo weight in kg; null until product weights are available. */
        public readonly ?float $totalWeightKg,
        public readonly ?float $totalDistanceKm,
        /** Report / print date shown on the cover (d.m.Y). */
        public readonly string $reportDate,
        /** Origin address line after "Von …". */
        public readonly ?string $fromAddress,
        /** Destination address line after "nach …" (usually back to warehouse). */
        public readonly ?string $toAddress,
        /** Optional overview map as data URI (image/png or image/jpeg). */
        public readonly ?string $mapImageDataUri = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'driverName' => $this->driverName,
            'totalWeightKg' => $this->totalWeightKg,
            'totalDistanceKm' => $this->totalDistanceKm,
            'reportDate' => $this->reportDate,
            'fromAddress' => $this->fromAddress,
            'toAddress' => $this->toAddress,
            'hasMapImage' => null !== $this->mapImageDataUri,
        ];
    }
}
