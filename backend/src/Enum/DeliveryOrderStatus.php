<?php

namespace App\Enum;

enum DeliveryOrderStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Refused = 'refused';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::Refused => true,
            self::Pending => false,
        };
    }
}
