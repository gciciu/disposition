<?php

namespace App\Enum;

enum RouteOriginType: string
{
    case Warehouse = 'warehouse';
    case CourierLocation = 'courier_location';
    case None = 'none';
}
