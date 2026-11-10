# Mail List / Bulk Mailer (multi-tenant hosted service)

Laravel 12 / PHP 8.4 / MariaDB / Redis + Horizon. A multi-tenant list manager and bulk mailer
operated by JDSystems (JDSS) as a service. All mail is sent through the JDSS Mailgun account;
each tenant has its own sending domain. First tenant: Rene Bates Auctioneers (weekly auction
announcements, about 40k recipients in three groups: bidders, clients, subscribers). A second
tenant may follow later.

Read docs/PLAN.md for the full spec, data model and per-phase acceptance criteria.

## Server rules (this repo lives on the IONOS VPS, path /var/www/maillist)
- The VPS may carry other workloads until the end of 2026, then it is exclusive to this app.
  Until David says otherwise: do discovery first, and do NOT change global nginx, PHP-FPM,
  MariaDB, Redis or firewall configuration, and do NOT restart shared services, without asking.
- Use a dedicated MariaDB database and user for this app, a dedicated Redis database index or key
  prefix, your own nginx server block file, and systemd units or Supervisor programs named maillist-*.
- Never run destructive commands outside /var/www/maillist and this app's own database. Do not
  touch other sites under /var/www.
- Secrets live in .env (chmod 600), never in git, never printed in output or logs. If a secret is
  needed, ask David to put it in .env.
- Do not expose the app publicly before authentication works. For development bind to localhost
  (php artisan serve or an nginx block restricted by IP or basic auth).
- If PHP 8.4 or another required component is missing, tell David what you propose to install
  and wait for approval; do not downgrade the stack silently.

## Working agreement
- Work one phase at a time. Start each phase by restating its acceptance criteria and a short plan.
- Write tests with the code (Pest). Run the full test suite and Pint before declaring a phase done.
- Never call live Mailgun, Spectrum, legacy or other upstream services from tests; use Http::fake()
  and fixtures.
- Small commits with clear messages. No destructive migrations without calling it out.
- Record decisions and open questions in docs/decisions.md. If a requirement is ambiguous, or
  depends on Spectrum or the legacy database schema, stop and ask; do not invent it.
- Do not build features for a prospective second tenant (other adapters, per-school senders)
  until Phase 7 is explicitly started.

## Tenancy rules (most important)
- Single database. Every tenant-owned table has a non-null tenant_id; every unique index includes it.
- Tenant-owned models use the BelongsToTenant trait (global scope plus tenant_id on create).
  Do not use withoutGlobalScopes() or raw queries on tenant data except in the super-admin area
  or an explicit, commented case.
- Every queued job stores tenant_id and restores tenant context before touching data. A job
  without tenant context must fail loudly. Console commands and webhook handlers set context
  explicitly.
- The Mailgun API key and webhook signing key are account-level secrets (.env or server secrets),
  never stored in tenant tables. A tenant is identified to Mailgun by its sending domain only.
  Per-tenant adapter settings are encrypted at rest (they depend on APP_KEY; never regenerate it).
- Unsubscribes and manual suppressions are per tenant. Hard bounces and complaints are also written
  to platform_suppressions, which is checked on every send.
- Imports and syncs never override a suppression or unsubscribe: nobody who opted out is
  re-subscribed by an import.
- A tenant cannot send until its sending domain is verified.
- Count messages sent per tenant per month (usage_counters).
- Maintain a tenant-isolation test suite (two tenants, overlapping data) that must pass in CI.
  Add to it whenever you add a model, route, export or job.

## Architecture rules
- All audience and content data enters through per-tenant AudienceSource / ContentSource adapters
  into the local mirror tables (recipients, content_items). Campaigns are built only from the
  mirror. A content item is an auction for Rene Bates (type = auction); other tenants may use
  course or event.
- Sending goes through the CampaignDispatcher interface. BatchApiDispatcher (Mailgun messages API
  with recipient-variables, using the account credentials and the tenant's sending domain) is the
  only implementation for now.
- Attach v:tenant_id, v:campaign_id and v:campaign_recipient_id to every message.
- Provider webhooks are converted by a normalizer into one internal event type; status logic and
  reporting never read provider payloads directly. Resolve the tenant from v:tenant_id and verify
  it against the sending domain; quarantine mismatches.
- Store a generic provider and provider_message_id, not Mailgun-specific columns.
- Jobs must be idempotent and safe to retry. Database state is the source of truth.
- Emails are trimmed, lowercased, unique per tenant, and checked against tenant and platform
  suppressions before sending.
- Status only moves forward (queued, accepted, delivered...). Events may arrive out of order or more
  than once; dedupe on the provider event id.
- Verify the Mailgun webhook signature on every request (HMAC-SHA256 of timestamp + token), reject
  stale timestamps and reused tokens.

## Compliance rules
- Every message has List-Unsubscribe and List-Unsubscribe-Post headers, a visible unsubscribe link,
  and the tenant's postal address in the footer.
- Unsubscribe tokens are signed and embed the tenant; a token from one tenant can never affect another.
- Public subscribe pages use double opt-in (confirmation email) before a recipient becomes active.

## Conventions
- declare(strict_types=1); Pint formatting; Pest tests; Form Requests for validation.
- Never log API keys, signing keys, or full webhook payloads at info level.
- Queues: sends, webhooks, imports. Horizon supervised.
- Open tracking is approximate (Apple Mail Privacy Protection); label it so in the UI.
