# Decisions and open questions

## Decisions

### D1. Stack (2026-11-10)
Laravel 12.72, PHP 8.4, MariaDB 10.6, Redis 6.0, Horizon 5, Livewire 4 with Volt, Breeze auth scaffold,
Pest 4, Pint. VPS stays on Ubuntu 22.04 LTS (PHP 8.4 from the sury repo, Node 22 via nvm under `ubuntu`).
App name KwikQue, served at app.kwikque.com.

### D2. Tenancy: small in-house implementation, no package
Single database; `tenant_id` on every tenant-owned table; `BelongsToTenant` trait (global `TenantScope`
plus `tenant_id` stamped on create). **Fails closed**: querying or creating tenant-owned data with no tenant
in context throws `MissingTenantContext`, rather than silently returning everything. Context lives in
`TenantContext`, bound with `scoped()` so it never leaks between requests or queued jobs.
- Requests: `ResolveTenant` re-validates the session tenant against membership on every request. A tampered
  session id is ignored. Super-admins may select any active tenant. Suspended tenants are not accessible.
- Jobs: every queued job extends `TenantJob`, which captures `tenant_id` at construction (throws if there is
  no context) and restores the context before `process()`. An arch test enforces this for `App\Jobs`.
- Commands: extend `TenantCommand`, which requires `--tenant=<slug>`.
- Roles per tenant: admin > operator > viewer, `role:<min>` middleware. Super-admin is a platform flag on
  `users` and counts as admin everywhere. Horizon and `/platform/*` are super-admin only in all environments.
- No public registration. Users are created by the seeder or later by an admin UI.

### D3. Tests run on MariaDB
PHP has no `pdo_sqlite` here and production is MariaDB, so tests use a separate `maillist_test` database
(`phpunit.xml`). The `maillist` DB user has grants on `maillist` and `maillist_test` only.

### D4. Queues
Horizon supervisors: `sends`, `webhooks`, `imports` (+ `default`). Redis DB 3 (queues) and 4
(cache), key prefix `maillist:`; Redis is bound to 127.0.0.1 with a password.

### D5. strict_types enforced through Pint
`pint.json` turns on `declare_strict_types`; an arch test checks `App`.

### D6. Rene Bates timezone (2026-11-10)
David said "CST"; stored as the IANA zone `America/Chicago` (Central, observes DST) so scheduled send times
stay at the same local time all year. Per-tenant `timezone` drives scheduling.

### D7. Basic-auth gate stays on app.kwikque.com for now (David, 2026-11-10)
Do not remove it from nginx until David says so.

### D8. Opt-out model (Phase 1)
Unsubscribes, manual blocks, bounces and complaints live in a permanent per-tenant `suppressions` row, keyed by
tenant + email, written only through `App\Services\OptOuts`. The recipient row carries mirrored flags
(`unsubscribed_at`, `suppressed_at`, `subscribed`). Deleting a recipient never deletes its suppression, so a
re-import or re-add of that address comes back opted out. The importer also checks the global
`platform_suppressions` table (hard bounces/complaints; intentionally not tenant-scoped, system-written only).
Operators can opt an address out but cannot re-subscribe one here: re-subscription needs a consent flow
(Phase 2 double opt-in). Open question: whether an admin may lift a manual suppression.

### D9. Import semantics
- One importer for CSV, manual add and paste-a-list (`RecipientImporter`), so protections are identical.
- Counts: created (new rows), updated (existing row whose name/external_id or group membership changed),
  unchanged, invalid, duplicate (same address twice in the file; first kept), and "kept opted out", an overlay
  count of rows that stay opted out whatever the file says. Re-importing a file yields created=0, updated=0.
- Name/external_id from a file overwrite only when the file provides a value. Source, consent fields and the
  subscribed flag of existing recipients are never changed by an import.
- New recipients from a file get `consent_source = import:csv` and no `consent_at` (consent date unknown).
- Editing a recipient's email is not supported (remove and re-add), to avoid moving opt-outs between addresses.
- CSV: UTF-8 (BOM tolerated), comma/semicolon/tab, header row; columns `email` (required), `name` or
  `first_name`+`last_name`, `external_id`. Max 10 MB. Errors are capped at 500 rows in the report; counts are exact.
- `LegacyDbSource` is not built: waiting for David's legacy schema/queries (PLAN section 9).

### D10. Legacy import files (default format)
Four CSVs exported from the legacy app are the default import format, one file per group:
`active_bidders_with_interests.csv` -> Bidders (`bidder_id,email,name`), `inactive_bidders_with_interests.csv` ->
Bidders - Inactive (`bidder_id,email,name`), `clients_with_interests.csv` -> Clients (`client_id,email,name`),
`subscribers_with_interests.csv` -> Subscribers (`email_id,email`, no name).
- The legacy app had no subscribe flag: having interests meant subscribed. Interests are not exported and not needed.
  **Every address in these files is treated as currently subscribed** (David, 2026-11-12). Consent date is unknown
  (`consent_source = import:csv`, no `consent_at`). Existing suppressions/unsubscribes still win (D8).
- Legacy ids are stored in `recipient_legacy_ids` (tenant_id, recipient_id, kind = bidder|client|email, legacy_id),
  one per kind per recipient, because the three legacy id spaces overlap. A person in two files holds both ids.
  A re-import with a different id for the same kind replaces it. Matching is always by email, never by legacy id.
- Cross-file overlap is expected (about 97 addresses); a recipient simply belongs to several groups.

### D11. Suppression import
Bulk opt-outs from a CSV (`email`, optional `reason`, `note`) or one-address-per-line file, on the Suppressions page.
- Strictly tenant-scoped (David, 2026-11-12): writes only this tenant's `suppressions`; never `platform_suppressions`,
  which stays system-written from provider events. A person in two tenants is blocked only in the one that imported.
- Double confirmation: upload shows a preview (new / already suppressed / invalid / duplicate / mailable people who
  will stop receiving mail) and writes nothing; applying needs the typed word SUPPRESS plus a browser confirm dialog.
  The previewed file and reason are server-locked, so the browser cannot swap them between preview and apply.
- Applied by a queued `ProcessSuppressionImport` (tenant-restored), recorded as an `import_runs` row with
  `source = suppressions` and `options.reason`. Existing suppressions are never changed. Audited.
- Lifting (David, 2026-11-12): tenant admins only (`lift-suppressions` gate; operators cannot). Needs a written
  reason (audited with the previous reason) and a confirm dialog; unsubscribe/complaint blocks show a consent
  warning. It deletes the tenant suppression and re-enables the recipient row, but never touches
  `platform_suppressions`, so a platform-wide bounce/complaint keeps blocking sends.

### D12. Tenant members
Platform admins manage who can log in to a tenant from Platform > Tenants > Members (role admin/operator/viewer;
change role; remove; remove keeps the login). Platform-admin only, including against a tenant admin. A new user
gets a generated 16-character temporary password shown once on screen (mail is still `MAIL_MAILER=log`, so no
invite is emailed); the account is pre-verified and the user should change the password under Profile. Re-adding an
existing user to a second tenant never changes their password. Platform admins already act as Admin in every tenant
and cannot be added as members. Open: email invitations once real mail is configured; forced password change on
first login; a "last admin" guard.

### D13. Mailpit dev mail catcher (2026-11-12)
Laravel SMTP (`MAIL_MAILER=smtp`, `127.0.0.1:1025`, no auth or encryption) goes to Mailpit, so nothing the app
sends by SMTP (password resets, later double opt-in) reaches a real address. Campaign mail is unaffected: it goes via
the Mailgun API, not SMTP.
- Mailpit v1.33.1, official binary at `/usr/local/bin/mailpit` (SHA-256 checked against the release digest), run as
  `maillist-mailpit.service` under the unprivileged `maillist-mailpit` user. SMTP and UI listen on localhost only
  (1025, 8025). Messages live in SQLite under `/var/lib/maillist-mailpit/`, capped at 5,000 (oldest pruned).
- UI at https://mailpit.kwikque.com via our own nginx file `/etc/nginx/sites-available/maillist-mailpit.conf`
  (symlinked in sites-enabled), Let's Encrypt cert from certbot, proxied by Cloudflare (SSL mode Full strict),
  HTTP basic auth always on: the inbox holds password-reset links. Credentials: `MAILPIT_UI_USER` and
  `MAILPIT_UI_PASSWORD` in `.env`; the nginx hash is `/etc/nginx/maillist-mailpit.htpasswd` (root:www-data 640).
  The websocket for live updates is proxied. No existing nginx server block, PHP-FPM, MariaDB or Redis config was changed.
- Before real users or live sends: set `MAIL_MAILER` and `MAIL_FROM_ADDRESS` (still the placeholder
  hello@example.com) to a real transactional route, and decide whether to keep Mailpit for staging only.
- Rotating the UI password: update `.env`, regenerate the htpasswd file (`htpasswd -iBc`) and reload nginx.

## Open questions for David (do not invent)
- ADMIN_PASSWORD must be put in `.env` by David before `php artisan db:seed`.
- Items from PLAN section 9 (Spectrum, legacy schema, unsubscribe link targets, sending subdomain and DNS owner,
  Mailgun limits/plan) remain open and block later phases, not Phase 0.
