<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case PreparToDelivery = 'preparToDelivery';
    case Delivering = 'delivering';
    case Done = 'done';
}
