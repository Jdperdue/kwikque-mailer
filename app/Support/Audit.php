<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Never put secrets or full payloads in $context. */
    public static function record(string $action, ?Model $subject = null, array $context = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
        ]);
    }
}
