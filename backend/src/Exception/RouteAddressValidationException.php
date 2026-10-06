<?php

namespace App\Exception;

class RouteAddressValidationException extends \InvalidArgumentException
{
    /**
     * @param list<array{
     *     position: int,
     *     clientName: string,
     *     address: string,
     *     error: string
     * }> $invalidAddresses
     */
    public function __construct(
        private readonly array $invalidAddresses,
        string $message = 'One or more addresses were not found in Google Maps',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{
     *     position: int,
     *     clientName: string,
     *     address: string,
     *     error: string
     * }>
     */
    public function getInvalidAddresses(): array
    {
        return $this->invalidAddresses;
    }
}
