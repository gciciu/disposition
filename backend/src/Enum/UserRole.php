<?php

namespace App\Enum;

enum UserRole: string
{
    case SuperAdmin = 'ROLE_SUPER_ADMIN';
    case Admin = 'ROLE_ADMIN';
    case Courier = 'ROLE_COURIER';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Administrator',
            self::Admin => 'Админ',
            self::Courier => 'Курьер',
        };
    }

    /** @return list<string> */
    public static function adminRoles(): array
    {
        return [self::SuperAdmin->value, self::Admin->value];
    }
}
