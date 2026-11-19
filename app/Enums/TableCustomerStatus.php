<?php

namespace App\Enums;

/** A customer's place at a table: the table's responsible decides who joins. */
enum TableCustomerStatus: string
{
    /** Asked to join; waiting for the table's responsible. */
    case PENDING = 'pending';
    /** At the table: sees the bill and orders. The responsible is always approved. */
    case APPROVED = 'approved';
    /** The responsible turned the request down. */
    case REJECTED = 'rejected';

    /** A customer turned down this many times can't ask to join that table again. */
    public const MAX_REJECTIONS = 3;
}
