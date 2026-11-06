<?php

namespace App\Dto\RouteCompletionPdf;

/**
 * One block in the "Retourschein" section
 * (exchange / return / cancelled stop).
 */
final class RouteCompletionPdfReturn
{
    public const TYPE_CONTAINER = 'Container';
    public const TYPE_WAREHOUSE_RETURN = 'Lager: Rückläufer';

    /**
     * @param list<string> $products
     */
    public function __construct(
        public readonly int $position,
        /**
         * Return destination label, e.g. "Container" or "Lager: Rückläufer".
         * Use TYPE_* constants when possible.
         */
        public readonly string $returnType,
        public readonly string $clientName,
        public readonly string $address,
        public readonly array $products,
        /**
         * Human-readable delivery outcome, e.g.
         * "Ja, Persönlich angenommen" / "Nein, Durch Dispo geschlossen".
         */
        public readonly string $deliveredLabel,
        public readonly ?string $remark,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'returnType' => $this->returnType,
            'clientName' => $this->clientName,
            'address' => $this->address,
            'products' => $this->products,
            'deliveredLabel' => $this->deliveredLabel,
            'remark' => $this->remark,
        ];
    }
}
