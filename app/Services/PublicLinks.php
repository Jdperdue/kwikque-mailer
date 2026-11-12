<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Recipient;

/** Builds the public unsubscribe/preferences URLs and the RFC 8058 headers for a recipient. */
class PublicLinks
{
    public function __construct(private TokenService $tokens) {}

    public function unsubscribeUrl(Recipient $recipient): string
    {
        return route('public.unsubscribe', $this->tokens->forRecipient($recipient, TokenService::UNSUBSCRIBE));
    }

    public function preferencesUrl(Recipient $recipient): string
    {
        return route('public.preferences', $this->tokens->forRecipient($recipient, TokenService::PREFERENCES));
    }

    /** @return array<string,string> */
    public function listUnsubscribeHeaders(string $url): array
    {
        return [
            'List-Unsubscribe' => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
