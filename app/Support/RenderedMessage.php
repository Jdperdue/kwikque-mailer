<?php

declare(strict_types=1);

namespace App\Support;

/** The one rendered form of a campaign message: preview, test send and (Phase 3) real sends all use it. */
final class RenderedMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {}
}
