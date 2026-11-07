# AI Lead CRM, phases 3 and 4: outreach, autonomy, replies, live alerts

Builds on the approved design (`docs/superpowers/specs/2026-11-05-ai-lead-crm-design.md`).

**One deliberate change from the design.** The design reused `EmailSequence` for outreach. Sequences send
static templates, so they cannot carry a per-lead AI draft or an approval step. Outreach here is its own
small pipeline (`lead_messages`) that still sends through `SendUserEmail`, the global suppression list and a
signed unsubscribe link. Replies are read from the existing `MailReceived` event.

## Tasks (built in order, each with tests, committed separately)

1. **Schema and models.** `lead_messages` (first, followup, reply drafts and what was sent), `lead_replies`
   (classified inbound mail), new columns on `lead_profiles` (autonomy, sender mailbox, playbook, follow-up
   count and delay, daily send cap) and `leads` (owner, follow-up clock). `AutonomyPolicy` (global default in
   Settings, per-profile override, the matrix as three yes/no questions).
2. **Drafting.** `LeadMessageDrafter`: AI writes a first email or a follow-up from the lead and the profile's
   playbook; output validated, links stripped, a missing AI never produces a message.
3. **Sending.** `LeadMessageSender`: the only place a lead email leaves. Re-checks kill switch, suppression,
   reply or unsubscribe state, the per-mailbox daily cap; adds the signed unsubscribe link; threads replies.
4. **Outreach runner** (`leads:outreach`, every 5 minutes): promote qualified leads to Contacts, draft, then
   send or queue for approval according to autonomy; schedule and draft follow-ups; stop on reply.
5. **Reply detection and handling.** `ReplyDetector` (listener on `MailReceived`) matches the sender to a lead,
   stops follow-ups, records the reply; `ReplyHandler` classifies (fixed list, validated), suppresses on
   unsubscribe, drafts an answer only from the playbook, escalates low confidence or sensitive replies, and
   obeys the autonomy matrix.
6. **Alerts.** `LeadReplyReceived` broadcast on the owner's private channel (live toast and badge), an email to
   the owner, and a nav badge for waiting approvals and unseen replies.
7. **Screens.** Approvals queue (approve, edit, discard), autonomy and outreach settings on the profile form and
   Lead Sources, lead detail shows messages and replies.
8. **Running in the background.** `live.bat` / docs for scheduler, queue worker and Reverb together.
9. **Browser test** of all three autonomy modes, follow-up, reply alert, with a local SMTP sink and fake AI.

## Autonomy matrix (from the design)

| Event | approve_all | auto_first_approve_replies | full_auto |
|---|---|---|---|
| First email and follow-ups | approval queue | sent automatically | sent automatically |
| Reply drafted | approval queue | approval queue | sent automatically |
| Low confidence or sensitive reply | escalated | escalated | escalated |
| Unsubscribe | suppressed | suppressed | suppressed |
