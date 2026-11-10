<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    /** Higher number means more privilege. */
    public function rank(): int
    {
        return match ($this) {
            self::Admin => 3,
            self::Operator => 2,
            self::Viewer => 1,
        };
    }

    public function atLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
