<?php

namespace App\Dto\DeliveryConfirmationPdf;

/**
 * Company letterhead block at the top of the Lieferbestätigung.
 */
final class DeliveryConfirmationPdfCompany
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $website,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
        ];
    }
}
