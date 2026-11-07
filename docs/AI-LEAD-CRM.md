# AI Lead CRM

Design: `docs/superpowers/specs/2026-11-05-ai-lead-crm-design.md`.
Plans: `docs/superpowers/plans/2026-11-05-ai-lead-crm-phase-1.md` (pull side) and
`docs/superpowers/plans/2026-11-06-ai-lead-crm-phase-2.md` (qualification and email reveal).

## Built so far (phases 1 and 2)

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
- **AI qualification** (`leads:qualify`, every 10 minutes): each new lead is judged against the
  profile's brief. Free code gates run first (no email available, no title and no company, excluded
  titles) and cost nothing. The AI returns only a score and a reason; the tier (hot / warm /
  rejected) is computed from the profile's thresholds, so the model can never promote a lead. Lead
  fields are third-party text: they are sanitised and fenced in the prompt. A failed AI call is
  retried (up to three attempts), but an AI outage does not use up attempts: after three
  consecutive failures a run stops and counts nothing against the leads.
- **Email reveal**: only leads that pass get their email revealed (about one platform credit each),
  within the profile's monthly budget, hot leads first. A lead is never revealed twice (per-lead
  lock plus a re-check inside it). A credit is counted only when an email comes back. A revealed
  address that duplicates another lead, an existing Contact (even a deleted one) or the suppression
  list is rejected and not stored. If saving the extra details fails after the platform has
  charged, the email is still kept.
- **Resilience**: one lead that throws an unexpected error cannot stall the profile; a run is
  limited to 8 minutes; overlapping runs are serialised per profile.
- **Leads** (`/leads`): pulled leads with status and profile filters, score and tier. Leads are NOT
  contacts: nothing here is reachable by a WhatsApp or email campaign. Each lead has a detail page
  (`/leads/{id}`) with its reason and a timeline of everything that happened to it.
- **Profile screen**: thresholds, AI judgements per day, reveal budget per month, credits used this
  month, queue counts, and a "Qualify new leads now" button.

## Apollo notes

- Search is free but returns only first name, obfuscated last name, title and company, no email.
- Revealing an email (`people/match`) costs about one credit, only when an email is returned.
- The key must be allowed to call people search (a master key is the safe choice).
- Apollo stops at page 500; the connector reports "no more pages" there.
- Network errors are shown without the request URL (it contains the lead's platform id).

## Choosing an AI model (tested with real OpenRouter models, 2026-11)

- **Qualification needs one AI call per lead**, plus one when a profile's search is built. On a
  free OpenRouter key the limit is **50 free-model requests per day**, so keep "AI judgements per
  day" at or below about 40 on the free tier.
- **Free model that worked best:** `nvidia/nemotron-3-ultra-550b-a55b:free`. It returned valid JSON on
  every test, resisted a prompt-injection title, and answers in about 5 seconds.
  `recursive/flint-v1.0:free` was a close second (a good fallback). `google/gemma-4-31b-it:free`
  was rate-limited upstream when tested, and `nvidia/nemotron-3.5-super-vl:free` once returned
  malformed JSON (the app handles that by retrying).
- **Most free models are reasoning models.** Their hidden thinking counts against `max_tokens`, so with a
  small budget they stop mid-thought and return nothing. `ai.openrouter.reasoning_headroom`
  (env `AI_OPENROUTER_REASONING_HEADROOM`, default 1600) is added to every OpenRouter request to
  cover it. Only generated tokens are billed, so non-reasoning models cost no more.
- **The qualifier tells the model what the search already matched** (titles, seniority, locations,
  company size, industry) and to take those as true. Without that, a real model marked CEOs and
  Founders low because a company name could not prove "fintech" or "Lagos".
- **Privacy:** free-tier models may log or train on prompts. Lead names, job titles and companies
  are personal data, so use a paid model or a provider with no data retention before running this
  on real leads, and check OpenRouter's data-policy settings.

## Operating it

- Needs the scheduler (`schedule:run` every minute) and a queue worker.
- Needs an AI provider configured in Settings. Without one, profiles save without a generated
  search and cannot be switched on, and qualification reports "No AI provider is configured".
- Permissions: `leads.view` (see leads), `leads.manage` (profiles, keys, kill switch, Qualify now);
  admin and manager.
- Environment fallback for the key: `APOLLO_API_KEY`. A key saved on Lead Sources wins.
- After deploying, re-run the roles seeder (the deploy script already does) and run the new migration
  (`2026_11_06_000001_add_qualification_fields`).
- Costs to watch: AI judgements per day (profile setting) and reveal credits per month (profile
  setting; 0 disables revealing). The kill switch on Lead Sources stops everything.

## Phase 3 carry-forward

- Promotion of a qualified lead to a Contact must use the `Contact::*IncludingTrashed` resolvers and
  restore the row; `Lead::contact()` must use `withTrashed()`.
- `lead_events.type` / `actor` are `string(32)`; SQLite will not enforce that, MySQL will.
- `LeadProfile.last_pulled_at` means "last attempted run", not "last page fetched".

## Outreach, autonomy and replies (phases 3 and 4)

Plan: `docs/superpowers/plans/2026-11-07-ai-lead-crm-phase-3-4.md`.

- **Autonomy** (Lead Sources for the default, the lead profile to override): **Manual** (every email and
  reply waits for a person), **Semi-automatic** (first emails and follow-ups go out by themselves, replies
  wait), **Fully automatic** (the AI also answers replies). At every level an unsubscribe request is honoured
  and a sensitive, unclear or low-confidence reply is passed to a person and never answered by the AI.
- **Outreach** (`leads:outreach`, every 5 minutes): a qualified lead with an email is added to Contacts (the
  deleted-contact-safe way) and the AI writes a first email from the profile's **playbook** (the only facts it
  may state; unknown links are stripped). Follow-ups (count and gap set per profile) are written after the wait
  if the lead has not answered. Hot leads first, a few drafts per run, a stop after three AI failures in a row.
- **Sending** has one path (`LeadMessageSender`): kill switch, suppression, "has the lead replied", the sender
  mailbox and the daily send cap are re-checked right before sending. A held message stays `approved` and goes
  out on a later sweep. Every email carries a signed one-click unsubscribe link.
- **Replies**: every inbound mail from a connected mailbox is matched to a lead by sender address. A match stops
  all outreach at once, alerts the owner and is classified by the AI (interested, question, not now, not
  interested, unsubscribe, out of office, other). Interested or a question gets an answer drafted from the
  playbook; an answer the AI is not sure of becomes "needs you".
- **Screens**: *Replies & approvals* (approve, edit then approve, discard, mark dealt with), a sidebar badge, the
  lead page (emails, replies, timeline), outreach settings on the profile, the default on Lead Sources.
- **Alerts** (live): a prospect replying, a reply that needs a person, and drafts waiting for approval each push a
  toast and update the sidebar badge instantly over Reverb, and (for replies and "needs you") email the owner.
  The owner is the person whose mailbox sends the outreach, plus the profile's creator.

## Running it in the background (needed for live alerts and automation)

Four things must be running: the web app, **Reverb** (instant alerts), a **queue worker** that includes the
`mail-send` and `mail-sync` queues, and the **scheduler**. Locally, `live.bat` starts all four. On the server:
supervisor runs the worker and Reverb (`deploy/`; rerun `deploy/install-supervisor.sh` so the worker also reads
`mail-send,mail-sync`) and cron runs `schedule:run` every minute.

Replies are only seen if the sending mailbox is **connected and syncing**: `MAIL_CLIENT_ENABLED=true` and the
mailbox added under Mailbox. If the sync is off, replies are never detected and follow-ups would keep going.

## Not built yet

- A board task for a person when a lead replies "interested" (alerts only for now).
- Promoting by hand, a pipeline board view, open/click tracking on lead emails.
- The Hunter, Lusha, Apify and ZoomInfo connectors.
