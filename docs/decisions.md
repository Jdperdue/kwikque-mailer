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

## Open questions for David (do not invent)
- ADMIN_PASSWORD must be put in `.env` by David before `php artisan db:seed`.
- Items from PLAN section 9 (Spectrum, legacy schema, unsubscribe link targets, sending subdomain and DNS owner,
  Mailgun limits/plan) remain open and block later phases, not Phase 0.
