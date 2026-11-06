<?php

namespace App\Dto\RouteCompletionPdf;

/**
 * One delivery stop row in the main table:
 * Pos | Kunde | Artikel | (kg) | Datum | Uhrzeit | Geliefert | Unterschrift | Bemerkungen
 */
final class RouteCompletionPdfStop
{
    /**
     * @param list<string> $products Product lines shown under Artikel
     */
    public function __construct(
        public readonly int $position,
        public readonly string $clientName,
        public readonly ?string $phone,
        public readonly string $address,
        public readonly array $products,
        /** Sum of product weights for this stop; null if unknown. */
        public readonly ?float $weightKg,
        /** Delivery date (d.m.Y) or null if not completed. */
        public readonly ?string $date,
        /** Delivery time (H:i) or null if not completed. */
        public readonly ?string $time,
        public readonly bool $delivered,
        /** Signature / photo proof as data URI; null if missing. */
        public readonly ?string $signatureImageDataUri,
        /** Customer note (Bemerkung). */
        public readonly ?string $customerRemark,
        /** Driver note (Fahrerbemerkung). */
        public readonly ?string $driverRemark,
    ) {
    }

    /**
     * Combined Bemerkungen cell text (customer + driver), matching sample PDF style.
     */
    public function remarksText(): ?string
    {
        $parts = [];
        if (null !== $this->customerRemark && '' !== trim($this->customerRemark)) {
            $parts[] = 'Bemerkung: '.$this->customerRemark;
        }
        if (null !== $this->driverRemark && '' !== trim($this->driverRemark)) {
            $parts[] = 'Fahrerbemerkung: '.$this->driverRemark;
        }

        return [] === $parts ? null : implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'clientName' => $this->clientName,
            'phone' => $this->phone,
            'address' => $this->address,
            'products' => $this->products,
            'weightKg' => $this->weightKg,
            'date' => $this->date,
            'time' => $this->time,
            'delivered' => $this->delivered,
            'hasSignature' => null !== $this->signatureImageDataUri,
            'customerRemark' => $this->customerRemark,
            'driverRemark' => $this->driverRemark,
            'remarksText' => $this->remarksText(),
        ];
    }
}
