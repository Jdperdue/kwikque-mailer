<?php

declare(strict_types=1);

namespace App\Support\Audience;

/** One row from any AudienceSource, before normalization. */
final class RecipientData
{
    public function __construct(
        public readonly int $row,
        public readonly ?string $email,
        public readonly ?string $name = null,
        public readonly ?string $externalId = null,
        public readonly ?string $consentSource = null,
    ) {}
}
