<?php

namespace App\Util;

final class AddressFormatter
{
    /**
     * Remove trailing country name/code from a postal address line.
     * Google often returns e.g. "…, 10115 Berlin, Deutschland".
     */
    public static function withoutCountry(?string $address): ?string
    {
        if (null === $address) {
            return null;
        }

        $trimmed = trim($address);
        if ('' === $trimmed) {
            return null;
        }

        $without = preg_replace(
            '/,?\s*(Deutschland|Germany|Österreich|Austria|Schweiz|Switzerland|Liechtenstein|Belgien|Belgium|Niederlande|Netherlands|Luxemburg|Luxembourg|Frankreich|France|Polen|Poland|Tschechien|Czechia|Czech Republic|DE|AT|CH|LI|BE|NL|LU|FR|PL|CZ)\s*$/iu',
            '',
            $trimmed,
        );

        if (!\is_string($without)) {
            return $trimmed;
        }

        $without = trim($without, " \t\n\r\0\x0B,");

        return '' === $without ? $trimmed : $without;
    }
}
