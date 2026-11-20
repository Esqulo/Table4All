<?php

namespace App\Enums;

/** How far a staff member can go in a feature of the restaurant. */
enum Permission: string
{
    case NONE = 'none';
    case READ = 'read';
    case WRITE = 'write';

    public function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::READ => 1,
            self::WRITE => 2,
        };
    }

    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    public static function min(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
