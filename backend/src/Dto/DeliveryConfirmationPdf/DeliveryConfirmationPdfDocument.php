<?php

namespace App\Dto\DeliveryConfirmationPdf;

/**
 * Payload for a single-stop Lieferbestätigung PDF
 * (sample: materials/KW 44 H. Wagner Pos.9.pdf).
 *
 * Sections:
 * 1) Company letterhead
 * 2) Title + route meta (Route, Position, Erstellt am, Status)
 * 3) Kundendaten
 * 4) Lieferdetails
 * 5) Gelieferte Ware
 * 6) Zusätzliche Informationen (optional)
 * 7) Lieferfoto / Unterschrift (optional proof image)
 */
final class DeliveryConfirmationPdfDocument
{
    public const METHOD_LEFT_ON_SITE = 'Abgestellt vor Ort';
    public const METHOD_PERSONAL = 'Persönlich angenommen';

    /**
     * @param list<string> $products Delivered article lines
     */
    public function __construct(
        public readonly DeliveryConfirmationPdfCompany $company,
        public readonly string $documentTitle,
        /** Full route line, e.g. "KW 26/44 H. Wagner 949 KG 1356 KM". */
        public readonly string $routeLabel,
        public readonly int $position,
        /** "d.m.Y, H:i Uhr". */
        public readonly string $createdAtLabel,
        /** e.g. "Geliefert". */
        public readonly string $statusLabel,
        public readonly string $customerName,
        public readonly string $customerAddress,
        public readonly ?string $customerPhone,
        public readonly ?string $customerEmail,
        public readonly string $driverName,
        /** "d.m.Y, H:i Uhr". */
        public readonly string $deliveryAtLabel,
        /** e.g. {@see self::METHOD_LEFT_ON_SITE}. */
        public readonly string $deliveryMethod,
        public readonly array $products,
        public readonly ?float $weightKg,
        public readonly ?string $additionalInfo,
        /** Proof photo/signature as data URI; null if missing. */
        public readonly ?string $proofImageDataUri,
        /** "Lieferfoto" or "Unterschrift". */
        public readonly string $proofSectionTitle,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company' => $this->company->toArray(),
            'documentTitle' => $this->documentTitle,
            'routeLabel' => $this->routeLabel,
            'position' => $this->position,
            'createdAtLabel' => $this->createdAtLabel,
            'statusLabel' => $this->statusLabel,
            'customerName' => $this->customerName,
            'customerAddress' => $this->customerAddress,
            'customerPhone' => $this->customerPhone,
            'customerEmail' => $this->customerEmail,
            'driverName' => $this->driverName,
            'deliveryAtLabel' => $this->deliveryAtLabel,
            'deliveryMethod' => $this->deliveryMethod,
            'products' => $this->products,
            'weightKg' => $this->weightKg,
            'additionalInfo' => $this->additionalInfo,
            'hasProofImage' => null !== $this->proofImageDataUri,
            'proofSectionTitle' => $this->proofSectionTitle,
        ];
    }
}
