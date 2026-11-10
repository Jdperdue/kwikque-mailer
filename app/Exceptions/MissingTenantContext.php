<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown whenever tenant-owned data is touched without a tenant in context. Never swallow it. */
class MissingTenantContext extends RuntimeException
{
    public static function for(string $what): self
    {
        return new self("No tenant context: refusing to {$what}.");
    }
}
