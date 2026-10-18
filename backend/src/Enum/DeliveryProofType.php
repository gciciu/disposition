<?php

namespace App\Enum;

enum DeliveryProofType: string
{
    case Photo = 'photo';
    case Signature = 'signature';
}
