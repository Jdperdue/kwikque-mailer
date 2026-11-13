<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent when someone submits the subscribe form with an address that is already subscribed. The page itself never says so. */
class AlreadySubscribed extends Mailable
{
    public function __construct(private Tenant $tenant, private string $preferencesUrl, private string $unsubscribeUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->tenant->from_address ?: (string) config('mail.from.address'), $this->tenant->from_name ?: $this->tenant->name),
            subject: 'You are already subscribed to '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.already-subscribed',
            text: 'mail.already-subscribed-text',
            with: [
                'tenantName' => $this->tenant->name,
                'preferencesUrl' => $this->preferencesUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'postalAddress' => trim((string) $this->tenant->postal_address),
            ],
        );
    }
}
