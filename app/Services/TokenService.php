<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Recipient;
use DateTimeInterface;

/**
 * Signed, purpose-scoped public-link tokens: base64url(json payload) . '.' . HMAC-SHA256.
 * The payload embeds the tenant, so the tenant is always taken from the verified token, never from the URL.
 * The key is derived from APP_KEY (never regenerate it: every link already sent would die).
 * Unsubscribe and preferences tokens do not expire (old emails must keep working); confirm tokens do.
 */
class TokenService
{
    public const UNSUBSCRIBE = 'unsubscribe';

    public const PREFERENCES = 'preferences';

    public const CONFIRM = 'confirm';

    public function forRecipient(Recipient $recipient, string $purpose, ?DateTimeInterface $expires = null, array $extra = []): string
    {
        return $this->make([
            't' => (int) $recipient->tenant_id,
            'r' => (int) $recipient->getKey(),
            'p' => $purpose,
            'e' => $expires?->getTimestamp(),
        ] + ($extra === [] ? [] : ['x' => $extra]));
    }

    /** @return array{tenant_id:int, recipient_id:int, extra:array}|null  null if malformed, tampered, expired or wrong purpose */
    public function verify(string $token, string $purpose): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        if (! hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($payload) || ! isset($payload['t'], $payload['r'], $payload['p']) || $payload['p'] !== $purpose) {
            return null;
        }

        if (isset($payload['e']) && $payload['e'] < now()->getTimestamp()) {
            return null;
        }

        return [
            'tenant_id' => (int) $payload['t'],
            'recipient_id' => (int) $payload['r'],
            'extra' => (array) ($payload['x'] ?? []),
        ];
    }

    private function make(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $body.'.'.$this->sign($body);
    }

    private function sign(string $body): string
    {
        $key = hash_hmac('sha256', 'maillist-public-token-v1', (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }
}
