<?php

declare(strict_types=1);

/*
 * Account-level Mailgun settings (JDSS account). Secrets live only in .env;
 * a tenant is identified to Mailgun by its sending domain (tenants.mailgun_domain).
 */
return [
    'api_key' => env('MAILGUN_API_KEY'),
    'signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    'base_url' => env('MAILGUN_BASE_URL', 'https://api.mailgun.net'),
    // Verify the current per-call recipient limit in Mailgun docs before Phase 3.
    'batch_size' => (int) env('MAILER_BATCH_SIZE', 1000),
];
