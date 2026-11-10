# Handoff: building the mail list app on the IONOS VPS with Claude Code

Target: /var/www/maillist on host `ionos`. Files in this bundle:
- CLAUDE.md  -> /var/www/maillist/CLAUDE.md (rules Claude Code reads every session)
- PLAN.md    -> /var/www/maillist/docs/PLAN.md (spec and acceptance criteria)
- HANDOFF.md -> this guide (keep it outside the repo or in docs/)

## 1. Place the files (run from your workstation)

    ssh ionos 'mkdir -p /var/www/maillist/docs'
    scp CLAUDE.md ionos:/var/www/maillist/CLAUDE.md
    scp PLAN.md   ionos:/var/www/maillist/docs/PLAN.md
    scp HANDOFF.md ionos:/var/www/maillist/docs/HANDOFF.md

Then: `ssh ionos`, `cd /var/www/maillist`, `claude`.

## 2. Have ready before or during the first session
- Mailgun: a sandbox or test domain, API key, and webhook signing key (put in .env yourself; never paste into chat).
- Hostnames (service brand is KwikQue, domain kwikque.com, registered elsewhere; DNS stays where it is for now):
  - APP_URL = https://app.kwikque.com (admin UI). Add an A record to the VPS IP before requesting a certificate.
  - Optional later: mail.kwikque.com for JDSS system mail (password resets), go.kwikque.com for tracking and
    public subscribe/unsubscribe pages if branded per tenant.
  - kwikque.com is not the sending domain for tenants; each tenant sends from its own subdomain
    (for Rene Bates, a subdomain of renebates.com, name still to be chosen).
  - Before adding any Mailgun records to kwikque.com, check the zone for stale MX/SPF/other records from the
    unlaunched CRM and tell David what you find.
  - Keep the app name and URL in config/.env only (APP_NAME, APP_URL); never hard-code the brand.
  - kwikwerk.com is owned but unused; do not configure it.
- Decision on the admin UI stack (Livewire assumed).
- Later, when Phase 1 reaches it: legacy database schema (or the queries the old mailer uses).
- Not needed yet: anything from Spectrum (Phase 5 is blocked).

## 3. Kickoff message (paste into Claude Code)

> Read CLAUDE.md and docs/PLAN.md fully. This is the JDSS multi-tenant list manager and bulk mailer; the
> first tenant is Rene Bates Auctioneers. Before writing any code, do a read-only preflight of this VPS and
> report: OS and version, PHP version and extensions (need 8.4), composer, node/npm, MariaDB version and
> existing databases, Redis, nginx and existing server blocks, supervisor/systemd availability, disk space,
> listening ports, other sites under /var/www, and certbot status. The app will be served at
> app.kwikque.com (APP_NAME=KwikQue); report whether DNS for that name currently resolves to this VPS.
> Change nothing. Then propose a plan for
> Phase 0 and list anything you need from me. Do not install or modify anything until I approve.

## 4. Phase prompts (send one at a time, after the previous phase is accepted)

Phase 0: "Execute Phase 0 from docs/PLAN.md. Scaffold Laravel 12 in a temporary directory and move its
contents into /var/www/maillist, preserving CLAUDE.md and docs/. Create a dedicated MariaDB database and
user (tell me the commands first), use a dedicated Redis prefix, set up Horizon, Pest, Pint. Build the tenancy
foundation and seed Rene Bates as tenant 1. Write docs/deploy.md and docs/decisions.md. Show the acceptance
checklist with results."

Phase 1: "Execute Phase 1. Include the ADDED items: manual recipient tools, manual content item form. Do not
build LegacyDbSource yet; stub the interface and wait for the schema. Extend the isolation suite."

Phase 2: "Execute Phase 2 including the ADDED public subscribe (double opt-in), preferences and unsubscribe
pages and the super-admin domain onboarding. Mailgun calls must be faked in tests."

Phase 3: "Execute Phase 3. Include the 40,000-recipient fake-Mailgun test and the two-tenant concurrency test."

Phase 4: "Execute Phase 4. Implement signature verification, normalizer, idempotent processing, quarantine, and
reporting. Add the nightly reconciliation command."

Phase 5 is blocked: do not start until Spectrum interoperability is known.

Phase 6: "Execute Phase 6 hardening and cutover items, and produce the runbook and cutover checklist."

Phase 7 only if a second tenant is signed.

When David supplies the legacy schema: "Build LegacyDbSource per PLAN.md: read-only, dry-run mode, delta
mode, imports unsubscribes and bounces as suppressions, never re-subscribes anyone."

## 5. Stop and ask David (Claude Code should halt on these)
- Any change to shared server config (nginx global, PHP-FPM pools, MariaDB, Redis, firewall) or service restarts.
- Installing PHP 8.4 or other missing components.
- Anything requiring a secret, DNS change, or a live Mailgun call.
- Anything that depends on Spectrum, the legacy schema, or legacy unsubscribe link targets.
- A destructive migration, or a requirement that seems to conflict with PLAN.md.

## 6. Notes
- Laravel scaffolding refuses a non-empty directory; scaffold elsewhere and move files (CLAUDE.md and docs/
  must survive).
- Keep APP_KEY stable once adapter settings are encrypted.
- Commits made by Claude Code should follow your usual attribution convention.
