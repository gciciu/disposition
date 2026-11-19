<?php

namespace App\Enum;

/**
 * Doc1 §4 delivery outcome selected by the driver.
 */
enum DeliveryMethod: string
{
    case Personal = 'personal';
    case LeftOnSite = 'left_on_site';
    case Neighbor = 'neighbor';
    case Partial = 'partial';
    case NotDelivered = 'not_delivered';

    public function isDeliveredOutcome(): bool
    {
        return match ($this) {
            self::Personal, self::LeftOnSite, self::Neighbor, self::Partial => true,
            self::NotDelivered => false,
        };
    }

    public function labelDe(): string
    {
        return match ($this) {
            self::Personal => 'Persönlich angenommen',
            self::LeftOnSite => 'Abgestellt vor Ort',
            self::Neighbor => 'Beim Nachbarn abgegeben',
            self::Partial => 'Teillieferung',
            self::NotDelivered => 'Nicht zugestellt',
        };
    }

    public function requiresSignature(): bool
    {
        return match ($this) {
            self::Personal, self::Neighbor => true,
            self::LeftOnSite, self::Partial, self::NotDelivered => false,
        };
    }

    public function requiresPhoto(): bool
    {
        return match ($this) {
            self::LeftOnSite => true,
            self::Personal, self::Neighbor, self::Partial, self::NotDelivered => false,
        };
    }

    public function allowsPhotoOrSignature(): bool
    {
        return self::Partial === $this;
    }
}
