<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campaign;
use App\Models\Tenant;
use App\Support\RenderedMessage;
use Illuminate\Support\Facades\View;

/**
 * Renders a campaign to subject, HTML and plain text from one data set, so what is previewed is what is sent.
 * Per-tenant branding comes from tenants.branding (logo_url, accent_color); a tenant may also override the whole
 * layout with resources/views/mail/tenants/{slug}/campaign(.text).blade.php.
 */
class CampaignRenderer
{
    /** Mailgun recipient-variable substituted per recipient at send time (Phase 3). */
    public const UNSUBSCRIBE_PLACEHOLDER = '%recipient.unsubscribe_url%';

    private const DEFAULT_ACCENT = '#1f2937';

    public function render(Campaign $campaign, Tenant $tenant, string $unsubscribeUrl = self::UNSUBSCRIBE_PLACEHOLDER): RenderedMessage
    {
        $tz = $tenant->timezone ?: 'UTC';
        $branding = (array) ($tenant->branding ?? []);

        $items = $campaign->items()->get()->map(fn ($item) => [
            'title' => $item->title,
            'type' => $item->type,
            'url' => $this->safeUrl($item->url),
            'image_url' => $this->safeUrl($item->image_url),
            'description' => trim((string) $item->description),
            'starts' => $item->starts_at?->setTimezone($tz)->format('D, M j, Y g:i A T'),
            'ends' => $item->ends_at?->setTimezone($tz)->format('D, M j, Y g:i A T'),
        ])->all();

        $accent = (string) ($branding['accent_color'] ?? '');
        $data = [
            'tenantName' => $tenant->name,
            'fromName' => $tenant->from_name ?: $tenant->name,
            'postalAddress' => trim((string) $tenant->postal_address),
            'logoUrl' => $this->safeUrl($branding['logo_url'] ?? null),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : self::DEFAULT_ACCENT,
            'subject' => $campaign->subject,
            'preheader' => trim((string) $campaign->preheader),
            'intro' => trim((string) $campaign->intro),
            'items' => $items,
            'unsubscribeUrl' => $unsubscribeUrl,
        ];

        return new RenderedMessage(
            subject: $campaign->subject,
            html: View::make($this->view($tenant, 'campaign'), $data)->render(),
            text: View::make($this->view($tenant, 'campaign-text'), $data)->render(),
        );
    }

    private function view(Tenant $tenant, string $name): string
    {
        $override = 'mail.tenants.'.$tenant->slug.'.'.$name;

        return View::exists($override) ? $override : 'mail.'.$name;
    }

    /** Only http(s) links and images are ever emitted (no javascript: or data: URLs from stored content). */
    private function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
