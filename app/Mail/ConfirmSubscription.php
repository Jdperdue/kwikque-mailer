<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Double opt-in confirmation. Sent over the app's SMTP, in the tenant's name. */
class ConfirmSubscription extends Mailable
{
    public function __construct(private Tenant $tenant, private string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->tenant->from_address ?: (string) config('mail.from.address'), $this->tenant->from_name ?: $this->tenant->name),
            subject: 'Please confirm your subscription to '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.confirm',
            text: 'mail.confirm-text',
            with: ['tenantName' => $this->tenant->name, 'url' => $this->url, 'postalAddress' => trim((string) $this->tenant->postal_address)],
        );
    }
}
