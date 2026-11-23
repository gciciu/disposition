<?php

namespace App\Enum;

/**
 * Where to send Lieferbestätigung after left_on_site delivery.
 */
enum ConfirmationEmailMode: string
{
    case Test = 'test';
    case Client = 'client';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (!\is_string($value)) {
            return null;
        }

        return self::tryFrom(trim($value));
    }

    public static function default(): self
    {
        return self::Test;
    }
}
