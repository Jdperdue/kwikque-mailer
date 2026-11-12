<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSuppression;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Support\Audit;

/**
 * The only code that records opt-outs. Keeps the permanent suppressions row and
 * the recipient flags in step, so removing or re-importing a recipient can never undo an opt-out.
 */
class OptOuts
{
    public function suppress(string $email, string $reason, ?string $note = null): Suppression
    {
        $suppression = Suppression::firstOrCreate(
            ['email' => $email],
            ['reason' => $reason, 'note' => $note],
        );

        $recipient = Recipient::where('email', $email)->first();

        if ($recipient !== null) {
            $this->applyTo($recipient, $suppression->reason);
        }

        if ($suppression->wasRecentlyCreated) {
            Audit::record('suppression.created', $suppression, ['reason' => $reason]);
        }

        return $suppression;
    }

    /**
     * Removes this tenant's suppression and clears the opt-out flags on the recipient row.
     * Does NOT touch platform_suppressions: a platform-wide bounce/complaint still blocks sending.
     * Returns the removed suppression's reason, or null if there was none. Audited with the reason given.
     */
    public function lift(string $email, string $why): ?string
    {
        $suppression = Suppression::where('email', $email)->first();

        if ($suppression === null) {
            return null;
        }

        $previous = $suppression->reason;
        $suppression->delete();

        $recipient = Recipient::where('email', $email)->first();
        if ($recipient !== null) {
            $recipient->forceFill(['subscribed' => true, 'unsubscribed_at' => null, 'suppressed_at' => null, 'suppression_reason' => null])->save();
        }

        Audit::record('suppression.lifted', $suppression, [
            'email_hash' => hash('sha256', $email),
            'previous_reason' => $previous,
            'why' => mb_substr($why, 0, 255),
            'platform_blocked' => PlatformSuppression::where('email', $email)->exists(),
        ]);

        return $previous;
    }

    /** Is this address blocked for this tenant (own list) or platform-wide? */
    public function reasonFor(string $email): ?string
    {
        return Suppression::where('email', $email)->value('reason')
            ?? PlatformSuppression::where('email', $email)->value('reason');
    }

    /** Stamp the opt-out onto a recipient row (does not save). */
    public function applyTo(Recipient $recipient, string $reason): void
    {
        $recipient->subscribed = false;

        if ($reason === 'unsubscribe') {
            $recipient->unsubscribed_at ??= now();
        } else {
            $recipient->suppressed_at ??= now();
            $recipient->suppression_reason = $recipient->suppression_reason ?? $reason;
        }

        if ($recipient->isDirty()) {
            $recipient->save();
        }
    }
}
