<?php

namespace App\Enums;

enum QueueItemStatus: string
{
    case PENDING = 'pending';
    case DONE = 'done';
    case DELIVERED = 'delivered';
    /** The table was closed before this item reached it: the customer left without receiving it. */
    case ABANDONED = 'abandoned';

    /**
     * Statuses of work that is still owed to a table.
     *
     * @return list<string>
     */
    public static function open(): array
    {
        return [self::PENDING->value, self::DONE->value];
    }
}
