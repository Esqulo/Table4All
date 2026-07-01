<?php

namespace App\Enums;

enum QueueItemStatus: string
{
    case PENDING   = 'pending';
    case DONE      = 'done';
    case DELIVERED = 'delivered';
}
