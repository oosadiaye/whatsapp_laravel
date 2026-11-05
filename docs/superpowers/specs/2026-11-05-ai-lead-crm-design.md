# AI Lead CRM: design

Date: 2026-11-05
Status: design approved in conversation; spec awaiting review. Nothing built yet.

## Goal

An admin describes the kind of lead they want. BlastIQ pulls matching leads from
lead-generation platforms, an AI qualifies each one, qualified leads receive
automated email outreach, and when a prospect replies the AI classifies the reply
and answers or escalates it. How much the AI may do unattended is an admin setting.

## Decisions already made

| Question | Decision |
|---|---|
| Platforms | Apollo first, then Hunter.io, Lusha, Apify/Outscraper, ZoomInfo, all behind one connector interface |
| Approach | Native in BlastIQ (not Clay/n8n, not Apollo-only) |
| How the admin defines a lead | Plain-English brief **and** optional hard filters; hard filters always win over the AI |
| Where pulled leads live | Separate `leads` table; only qualified leads are promoted to Contacts |
| Autonomy | Three levels, all built, admin-selectable: approve everything / auto first emails + approve replies / fully automatic. Global default, overridable per profile |
| Sender | A connected mailbox (`EmailAccount`) chosen per profile, so replies arrive in a mailbox the existing inbound sync already reads |

## Decomposition

Five phases, built in order, each with its own plan, tests and a shippable result:

1. Lead sources and profiles (pull, de-duplicate, caps), with the Apollo connector
2. AI qualification
3. Outreach (promote, enrol, approvals, send caps) and reply detection
4. AI reply handling and the autonomy levels
5. Remaining connectors (Hunter, Lusha, Apify, ZoomInfo)

## What already exists and is reused

- `EmailSequence` / `EmailSequenceStep` / `EmailSequenceRecipient`, processed by
  `email-sequences:process`: sends through a connected `EmailAccount` via
  `SendUserEmail`, checks `EmailSuppression`, adds a signed unsubscribe link and
  open/click tracking, and claims recipients atomically.
- `EmailSyncService` + `MailReceived` event: inbound mail from connected mailboxes.
- `AiClient` provider layer (Gemini or OpenRouter), `AiConfig`, per-feature flags.
- `Contact` (with soft-delete: new write paths must use the `*IncludingTrashed`
  resolvers), `EmailSuppression`, `AuditService`, the task board and
  `TaskAssignmentNotifier`.

## Known gap this project must fix first

Nothing sets `EmailSequenceRecipient::reply_count` or the `replied` status, and
nothing listens to `MailReceived` for sequences. Today a sequence keeps emailing
someone who has already replied. With automated outreach this is unacceptable, so
reply detection ships in sub-project 3 before any automatic sending is enabled.

## Data model

All new tables; no changes to existing tables except a nullable `lead_id` on
`email_sequence_recipients` if needed for matching.

**`lead_profiles`**: `name`, `is_active`, `brief` (text), `hard_filters` (json:
countries, title include/exclude, industries, company size range, require
verified email), `sources` (json: connector keys), `lead_query` (json: the
structured query derived from the brief, reviewed by the admin),
`daily_pull_cap`, `monthly_credit_budget`, `autonomy` (`approve_all` |
`auto_first_approve_replies` | `full_auto`, nullable = use global default),
`sender_account_ids` (json), `email_sequence_id`, `playbook` (text: facts the AI
may state in replies), `hot_threshold`, `warm_threshold`, `created_by`.

**`leads`**: `lead_profile_id`, `source`, `source_id`, `email`, `email_status`,
`name`, `title`, `company`, `domain`, `country`, `phone`, `linkedin_url`, `raw`
(json), `score`, `tier` (`hot` | `warm` | `rejected`), `qualification_reason`,
`status` (`new`, `qualified`, `rejected`, `contacted`, `replied`, `meeting`,
`won`, `lost`, `unsubscribed`), `contact_id` (nullable), `owner_user_id`,
`personal_opener`, `last_activity_at`. Unique on `email` (global) and on
`(source, source_id)`.

**`lead_events`**: `lead_id`, `type` (`pulled`, `enriched`, `qualified`,
`rejected`, `promoted`, `enrolled`, `email_sent`, `replied`, `classified`,
`draft_created`, `draft_approved`, `reply_sent`, `escalated`, `suppressed`),
`payload` (json), `actor` (user id or `system`/`ai`).

**`lead_replies`**: `lead_id`, `email_message_id`, `classification`
(`interested` | `question` | `not_now` | `not_interested` | `unsubscribe` |
`out_of_office` | `other`), `confidence`, `draft_body`, `status`
(`pending_approval` | `approved` | `sent` | `auto_sent` | `discarded` |
`escalated`), `decided_by`.

API keys for each platform are stored encrypted in Settings (same mechanism as the
AI key); the global kill switch and global autonomy default are settings too.

## Components

**`LeadSource` interface** (one class per platform): `key()`, `isConfigured()`,
`testConnection()`, `search(LeadQuery, int $limit, ?string $cursor): LeadPage`
(normalised `LeadData` objects, next cursor, credits used), and for platforms that
hide emails in search results, `reveal(LeadData): LeadData` (this is where credits
are spent).

**`LeadQueryBuilder`**: on saving a profile, asks the AI to turn the brief and hard
filters into a structured `LeadQuery`, stored on the profile. The admin sees and can
edit it ("here is what I will search for"). Pulls never call the AI.

**`PullLeadsJob`** (scheduled): per active profile and source, respects the daily
cap, the monthly credit budget and the global kill switch; de-duplicates against
`leads`, `contacts` and `email_suppressions` before saving.

**Two-stage pull:** save partial leads, qualify on title/company first, call
`reveal()` (credits) only for leads that pass. Whether a platform needs this is a
property of its connector.

**`LeadQualifier`**: code checks the hard filters first (cost-free rejection with a
reason). Then the AI returns `{score 0-100, tier, reason, red_flags}` as JSON,
validated against a fixed schema. On any AI failure or missing configuration the
lead stays `new` and is retried; it is never qualified by default. A daily AI cap
per profile bounds cost.

**`LeadPromoter`**: promotes a qualified lead to a `Contact` (using the
`*IncludingTrashed` resolvers) and creates the `EmailSequenceRecipient`. Under
`approve_all` it parks the lead in an approvals queue instead of enrolling it.
A hot lead also creates a board task for a person.

**Send cap:** a per-mailbox daily send cap enforced in the sequence processor
before dispatch.

**`ReplyDetector`**: listener on `MailReceived`. Matches the sender (and thread
headers) to a lead, marks the sequence recipient `replied`, stops further sequence
emails, sets the lead `replied`, records an event.

**`ReplyHandler`**: classifies the reply (AI, validated output) and acts:
`unsubscribe` always adds the address to the suppression list immediately;
`not_interested` marks the lead lost; `out_of_office` pauses and takes no action;
`interested` / `question` produce a drafted answer built only from the profile's
playbook. Anything the playbook cannot answer, any low-confidence result and any
sensitive reply (complaint, legal threat, anger) is escalated as a task for the
lead's owner.

## Autonomy matrix

| Event | Approve everything | Auto first, approve replies | Fully automatic |
|---|---|---|---|
| First email to a qualified lead | approval queue | sent by sequence | sent by sequence |
| Reply drafted | approval queue | approval queue | sent automatically |
| Reply is low-confidence or sensitive | escalated | escalated | escalated |
| Unsubscribe request | suppressed | suppressed | suppressed |

## Safety

- Global kill switch halts pulling, qualifying, enrolling and all automatic sending.
- Auto-pause of a profile when its bounce rate exceeds a threshold.
- Every AI decision is a `lead_events` row and an audit-log entry.
- A prospect's reply is untrusted input: the AI's classification is checked against
  the fixed list; drafts may not contain links or attachments absent from the
  playbook; the model has no tools.
- Unsubscribe link and suppression check on every outbound email (already present
  in the sequence sender).
- Compliance (NDPA and the recipient's local anti-spam law) is the operator's
  responsibility; the product provides the unsubscribe, suppression and an audit
  trail. Not legal advice.

## Admin screens

Settings: platform API keys with "test connection", global autonomy default, kill
switch. Lead Profiles: create/edit (brief, hard filters, reviewable generated query,
sources, caps, autonomy, sender mailboxes, sequence, playbook). Leads: pipeline
(table and board by status), lead detail with the event timeline. Approvals: first
emails and reply drafts awaiting a decision, one-click approve or edit.

## Errors and edge cases

- Platform error or rate limit: retry with backoff, record the failure on the
  profile, never lose the cursor; a failing source does not block the others.
- Credits exhausted: stop that source, show "budget reached" on the profile.
- Duplicate or already-known email: skipped silently, counted in the pull summary.
- Soft-deleted contact with the same email: restored via the `*IncludingTrashed`
  path, not a crash on the unique index.
- AI returns invalid JSON: treated as failure, lead stays `new`.

## Testing

Contract tests for each connector with `Http::fake`; `LeadData` normalisation;
dedupe against leads, contacts and suppression; caps and budget; qualifier with a
fake `AiClient` including the failure path; the autonomy matrix (every cell);
reply detection stops a sequence; unsubscribe suppresses; kill switch; permission
checks on every screen; one real-browser run per phase.

## Open items to verify during planning

- Apollo's current API: which calls return emails, which consume credits, plan
  requirements, rate limits. Design assumes search returns partial data and a
  separate reveal costs credits; confirm against current documentation.
- API terms and pricing for Lusha, ZoomInfo and the Apify/Outscraper actors before
  their connectors are built (sub-project 5).
- Which permission gates the Leads area (proposal: new `leads.*` permissions seeded
  for admin and manager).
