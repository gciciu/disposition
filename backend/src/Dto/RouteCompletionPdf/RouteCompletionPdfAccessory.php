<?php

namespace App\Dto\RouteCompletionPdf;

/**
 * One row in the "Zubehör" (accessories) section:
 * Pos | Zubehör | Geliefert
 */
final class RouteCompletionPdfAccessory
{
    public function __construct(
        /** Stop position this accessory belongs to. */
        public readonly int $position,
        /** Accessory line, e.g. "1 x FIX-IN". */
        public readonly string $description,
        public readonly bool $delivered,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'description' => $this->description,
            'delivered' => $this->delivered,
        ];
    }
}
