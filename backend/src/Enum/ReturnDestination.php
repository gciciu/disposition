<?php

namespace App\Enum;

/**
 * Warehouse disposition for returned / exchanged goods (Retourschein).
 */
enum ReturnDestination: string
{
    case WarehouseReturn = 'warehouse_return';
    case WarehouseShelf = 'warehouse_shelf';
    case Container = 'container';
    case Office = 'office';

    public function labelDe(): string
    {
        return match ($this) {
            self::WarehouseReturn => 'Lager: Rückläufer',
            self::WarehouseShelf => 'Lager: Regal',
            self::Container => 'Container',
            self::Office => 'Büro',
        };
    }
}
