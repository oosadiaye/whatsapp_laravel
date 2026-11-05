# AI Lead CRM

Design: `docs/superpowers/specs/2026-11-05-ai-lead-crm-design.md`.
Phase 1 plan: `docs/superpowers/plans/2026-11-05-ai-lead-crm-phase-1.md`.

## Built so far (phase 1)

- **Lead Sources** (`/lead-sources`): store a platform API key (encrypted), test the connection,
  and the global **pause all automation** switch.
- **Lead Profiles** (`/lead-profiles`): a plain-English brief plus hard filters. The AI turns the
  brief into a search once, on save; you can read it on the profile page. Hard filters always win
  over the AI (countries and company size replace, required titles come first, excluded titles
  are removed).
- **Pulling**: `leads:pull` runs hourly and queues one page per active profile and source. The daily
  limit is soft (checked before each page, counted from midnight in `app.business_timezone`). A
  failed page is retried on the next run and the cursor never skips a page. One unreadable row is
  logged and skipped; it does not stall the profile. Overlapping pulls (the hourly job and "Pull a
  page now") are serialised by a per-profile lock.
- **Leads** (`/leads`): pulled leads with status and profile filters. Leads are NOT contacts: nothing
  here is reachable by a WhatsApp or email campaign.

## Apollo notes

- Search is free but returns only first name, obfuscated last name, title and company, no email.
- Revealing an email (`people/match`) costs about one credit. Phase 1 never reveals; phase 2 will,
  only for leads that pass qualification, and must not call it twice for the same lead.
- The key must be allowed to call people search (a master key is the safe choice).
- Apollo stops at page 500; the connector reports "no more pages" there.

## Operating it

- Needs the scheduler (`schedule:run` every minute) and a queue worker.
- Needs an AI provider configured in Settings, or a profile saves without a generated search and
  cannot be switched on until you use "Rebuild search".
- Permissions: `leads.view` (see leads), `leads.manage` (profiles, keys, kill switch); admin and manager.
- Environment fallback for the key: `APOLLO_API_KEY`. A key saved on Lead Sources wins.
- After deploying, re-run the roles seeder (the deploy script already does) so the new permissions exist.

## Carry-forward to phase 2

- `leads.email` is globally unique and nullable: lowercase and trim on write, and treat a unique
  violation on reveal as "duplicate lead", not a failed job.
- `leads.contact_id` points at the soft-deleting `contacts` table: promotion must use the
  `Contact::*IncludingTrashed` resolvers, and `Lead::contact()` must use `withTrashed()`.
- `lead_events.type` / `actor` are `string(32)`; SQLite will not enforce that, MySQL will.
- `LeadProfile.last_pulled_at` means "last attempted run", not "last page fetched".

## Not built yet

AI qualification, revealing emails, promoting to Contacts, email outreach, reply handling, the
autonomy levels, and the Hunter, Lusha, Apify and ZoomInfo connectors.
