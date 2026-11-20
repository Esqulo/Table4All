<?php

namespace App\Enums;

/**
 * What a staff account (AccountType::WAITER) is in the team.
 *
 * - Waiter: reaches only the features the restaurant granted, each read-only or with writing.
 * - Manager: sees every feature of the restaurant (at least read-only), writes where granted,
 *   and manages the waiters. Never edits the venue itself, its finances, nor posts in its name;
 *   only the owner manages managers.
 */
enum StaffRole: string
{
    case WAITER = 'waiter';
    case MANAGER = 'manager';
}
