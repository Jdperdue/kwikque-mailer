# Mail List / Bulk Mailer: condensed plan

Source of truth for scope and acceptance criteria. Full write-up lives in Confluence (JDSystems
space, "Rene Bates Bulk Mailer (Laravel) - Implementation Plan"); this file is the self-contained
version for Claude Code. Items marked ADDED were agreed in conversation after the Confluence
plan v4 and have not yet been copied there.

## 1. Context
Rene Bates Auctioneers is migrating its live auction business from a legacy auction system to
Spectrum (SaaS). The legacy system feeds a bulk mailer that David wrote about 12 years ago. It
announces running and upcoming auctions to active bidders, clients and mailing-list subscribers.
Spectrum will not provide that, so this project is a functional equivalent in Laravel.

Legacy behaviour to reproduce:
- Pulls active auctions, subscribed bidders, clients and mailing-list subscribers from the legacy system.
- Admin selects which upcoming auctions go into a mailer; the message is composed from those.
- Recipients are submitted to Mailgun by API in blocks of 5,000.
- Admin can schedule delivery per subscriber group (bidders, clients, subscribers).
- Mailgun reports status per email address by webhook; the app records updates per address and
  shows summaries (sent, delivered, opened, etc.).

Product direction: multi-tenant from the start, run by JDSystems as a hosted service. Rene Bates is
the only tenant at launch. Mike Jones Group / AuctionAcademy is a prospective second tenant.

Decisions made:
- All mail goes through the JDSS Mailgun account, with one sending domain per tenant.
- Hosted as a service on a JDSS-operated VPS (the IONOS VPS at /var/www/maillist).
- Direct batch sending through the Mailgun API (not Mailgun Mailing Lists); Mailgun is the provider
  for the first release; keep the app provider-agnostic (dispatcher interface plus webhook normalizer).
- Schools (AuctionAcademy) are deferred until a second tenant is signed.

Non-goals for v1: self-serve tenant signup or billing, A/B testing, drag-and-drop template editor,
SMS, replacing Spectrum's own transactional notices, any feature for a prospective tenant.

Sizing: about 40,000 recipients across three lists, mailed about weekly (about 173,000 messages a
month). Throughput is not a constraint; correctness, tenant isolation and auditability are.

## 2. Architecture summary
- Laravel 12, PHP 8.4, MariaDB, Redis, Horizon, Livewire admin (or Inertia if David chooses), Pest, Pint.
- Tenancy: single database, tenant_id on every tenant-owned table, global scope via BelongsToTenant.
  Small in-house implementation preferred over a package; record the choice in docs/decisions.md.
- Queues: sends, webhooks, imports. Scheduler runs every minute and dispatches due campaigns.
- Mail delivery: CampaignDispatcher interface; BatchApiDispatcher uses the Mailgun messages API with
  recipient-variables. Mailgun limits recipients per call (historically 1,000): configurable
  MAILER_BATCH_SIZE, verify the current limit in Mailgun docs.
- Webhooks: one endpoint, account-level signing key, normalizer to one internal event type.
- Adapters per tenant:
  - ContentSource::activeItems(Tenant): featured items for a mailer (auctions, courses, events)
  - AudienceSource::recipients(Tenant, AudienceGroup)
  Implementations: CsvImportSource (universal fallback), manual admin forms (ADDED), LegacyDbSource
  (read-only seeding, ADDED as a cutover step), SpectrumSource (blocked), later tenant-specific ones.
- Sync is "pull into a local mirror"; campaigns build only from the mirror.

## 3. Data model (all tenant-owned tables carry tenant_id; unique indexes include it)
- tenants: name, slug, status, branding JSON, from_name, from_address, reply_to, postal_address,
  timezone, mailgun_domain (unique), domain_status, domain_verified_at, send_limits JSON
- users, tenant_users (role per tenant: admin, operator, viewer); platform super-admin flag
- tenant_sources: kind (content, audience), adapter class, encrypted settings, schedule, last status
- content_items: external_id, source, type, title, starts_at, ends_at, url, image_url, description,
  meta JSON, is_active, synced_at
- recipients: email (unique per tenant, lowercased), name, external_id, source, subscribed,
  unsubscribed_at, suppressed_at, suppression_reason, consent_source, consent_at, synced_at
- audience_groups, recipient_group (a recipient can be in several groups)
- campaigns, campaign_groups, campaign_items (with sort_order)
- campaign_recipients: email, provider, provider_message_id, status (queued, accepted, delivered,
  failed, bounced, complained, unsubscribed), opened_at, first_clicked_at, open_count, click_count,
  last_event_at, failure_reason
- provider_events: tenant_id nullable until resolved, provider, event_id (unique per provider), type,
  email, payload JSON, occurred_at, received_at, quarantine flag
- send_batches: campaign_id, batch_no, size, status, attempts, provider_response, dispatched_at
- email_templates, suppressions (tenant), platform_suppressions (global, hard bounces and complaints),
  usage_counters (tenant, month, messages_sent), import_runs, audit log, subscription_confirmations (ADDED)

## 4. Sending pipeline
1. Operator picks content items, audience groups and a send time (tenant time zone).
2. Scheduler dispatches PrepareCampaign (with tenant_id) for due campaigns across tenants.
3. Snapshot campaign_recipients: lowercase, dedupe across groups, drop unsubscribed, tenant- and
   platform-suppressed and invalid addresses; enforce tenant send limits; refuse if domain unverified.
4. Split into send_batches; each is a queued job calling the CampaignDispatcher.
5. Submit with recipient-variables, v:tenant_id, v:campaign_id, v:campaign_recipient_id and tags.
6. Record provider message ids, mark rows accepted, increment usage counters.
7. Idempotent per batch, exponential backoff on 429/5xx, pause/resume/cancel.
8. When all batches finish the campaign is sent and totals are cached.

## 5. Webhook rules
POST /webhooks/mailgun, CSRF-exempt, separately rate-limited. Verify HMAC-SHA256 (timestamp + token)
with the account signing key; reject stale timestamps and reused tokens. Respond 200 immediately,
store the raw event, process on the webhooks queue. Normalize; resolve tenant from v:tenant_id and
verify against the sending domain (quarantine mismatches). Dedupe on event id; status only moves
forward. Hard bounces and complaints write to tenant suppressions and platform_suppressions.
Nightly reconciliation against the Mailgun Events API.

## 6. Public pages (ADDED; per tenant, branded)
Legacy site pages Subscribe, Unsubscribe and Update Your Subscription disappear when renebates.com
DNS points to Spectrum at cutover, so the new service must host replacements:
- Subscribe page and embeddable form with double opt-in
- One-click and page-based unsubscribe
- Preferences page (choose groups)
Old unsubscribe links in already-sent legacy emails may break at cutover; where those links point
is unknown (see section 9).

## 7. Phases and acceptance criteria

Phase 0 - Foundations and tenancy.
Scaffold Laravel 12, MariaDB, Redis, Horizon, Pest, Pint, CI. Tenancy foundation (tenants,
tenant_users, trait, scope, context for requests/jobs/commands, super-admin tenant switcher). Seed
Rene Bates as first tenant. Per-tenant roles. Mailgun config from account-level secrets. Deployment
notes in docs/deploy.md (nginx, PHP-FPM, Horizon, scheduler, backups).
Accept: app boots; admin can log in; second test tenant can be created; isolation suite exists and
passes; a job dispatched without tenant context fails; Horizon and schedule:run work.

Phase 1 - Data model, audience and content intake.
Migrations; ContentSource/AudienceSource; CsvImportSource with validation, dedupe, normalization,
import history; audience pages (counts, history, suppressions); ADDED: manual recipient add/edit/
remove and paste-a-list; ADDED: manual content item create/edit form; ADDED: LegacyDbSource
seeding command (dry run, delta) built only after David supplies the legacy schema or queries.
Accept: re-importing a file is idempotent; invalid and duplicate rows are reported; same email in two
tenants stays independent; imports never re-subscribe a suppressed address; isolation suite extended.

Phase 2 - Campaigns, templates, domain onboarding, public pages.
Campaign CRUD (item selection and ordering, groups, schedule); per-tenant branded Blade templates (HTML
and plain text); preview and test send; unsubscribe tokens and landing page; ADDED: subscribe (double
opt-in), preferences and unsubscribe public pages; super-admin domain onboarding (add domain via
Mailgun API, show DNS records, check verification, register webhook, block sending until verified).
Accept: preview matches sent HTML; unsubscribe/confirmation token flows work end to end; a token from
one tenant cannot affect another; unverified-domain tenant cannot send (Mailgun faked).

Phase 3 - Sending pipeline and scheduling.
As section 4, plus per-tenant send limits and usage counters.
Accept (Http::fake): a 40,000-recipient campaign across overlapping groups sends one message per unique
address with the expected number of API calls; two tenants sending simultaneously never mix recipients
or domains; a killed worker resumes without duplicates; canceled campaigns stop.

Phase 4 - Webhooks and reporting.
As section 5, plus dashboards (counts and rates, opens labelled approximate), per-recipient timeline
search, CSV export.
Accept: replaying an event changes nothing; out-of-order events resolve correctly; tampered signature
returns 401/403; tenant/domain mismatch is quarantined.

Phase 5 - Spectrum integration. BLOCKED until Spectrum interoperability is known. May include a
ScrapedContentSource for Spectrum-rendered pages only if no API/feed exists and Spectrum's terms allow.

Phase 6 - Hardening and cutover.
Load test at and above 40k with two tenants; isolation review of every query, export, job, route;
alerts (failed jobs, webhook silence, bounce/complaint spikes, disk, queue depth); auto-pause on
thresholds; kill switch; backups with a tested restore; runbook; send-rate ramp for the first sends
on the JDSS account; cutover checklist (DNS for the mail subdomain, preserve MX/SPF/DKIM when
renebates.com web records change); legacy seed, final delta import and legacy suppression import;
parallel run against a seed list.

Phase 7 - Second tenant. Contingent on a signed tenant.

## 8. Cutover notes
- At cutover DNS for renebates.com will point to the Spectrum instance. Website DNS changes must not
  clobber MX, SPF, DKIM or the Mailgun records. Create and verify the mail subdomain records well
  before cutover.
- 100% of the audience is available from the legacy database for seeding. If Spectrum has no data
  interface at first, the client updates the audience manually or by periodic CSV export/upload.
- Campaign links must use Spectrum-era auction URLs, not legacy a_main_2.php?id= style links.

## 9. Unknowns: do not invent, ask David
- Spectrum interoperability (API, export, webhooks, feed, structured data on public pages, preview
  URL, whether renebates.com keeps an auction calendar after cutover).
- Legacy database schema and where legacy unsubscribe/bounce history lives.
- Where the legacy mailer's unsubscribe links point, and whether legacy stays reachable under another
  hostname after cutover.
- René Bates sending subdomain and who manages its DNS.
- Current Mailgun per-call recipient limit, domain/webhook registration API details, plan tier.
- Admin UI stack (Livewire assumed).
