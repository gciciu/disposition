<?php

namespace App\Dto\RouteCompletionPdf;

/**
 * Full payload for the route completion PDF.
 *
 * Sections mirror the sample layout:
 * 1) Cover (header + map)
 * 2) Delivery table (stops)
 * 3) Accessories (Zubehör)
 * 4) Returns (Retourschein)
 */
final class RouteCompletionPdfDocument
{
    /**
     * @param list<RouteCompletionPdfStop>      $stops
     * @param list<RouteCompletionPdfAccessory> $accessories
     * @param list<RouteCompletionPdfReturn>    $returns
     */
    public function __construct(
        public readonly RouteCompletionPdfHeader $header,
        public readonly array $stops,
        public readonly array $accessories = [],
        public readonly array $returns = [],
        public readonly string $footerLabel = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header->toArray(),
            'stops' => array_map(
                static fn (RouteCompletionPdfStop $stop): array => $stop->toArray(),
                $this->stops,
            ),
            'accessories' => array_map(
                static fn (RouteCompletionPdfAccessory $row): array => $row->toArray(),
                $this->accessories,
            ),
            'returns' => array_map(
                static fn (RouteCompletionPdfReturn $row): array => $row->toArray(),
                $this->returns,
            ),
            'footerLabel' => $this->footerLabel,
        ];
    }
}
