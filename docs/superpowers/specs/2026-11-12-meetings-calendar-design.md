# Meetings & calendar — design (Release 1)

Approved by the user 2026-11-12. Zero running cost: no paid video platform, no media server.

## Goal
A team calendar inside BlastIQ that tracks meetings automatically (and the user's task due dates),
invites staff and clients by email, collects accept/decline, reminds people, and links out to
Google Meet / Microsoft Teams through a generic video-link field. No video is carried by BlastIQ itself
(own WebRTC video is a later release; the data model does not depend on a provider).

## Scope
1. **Meetings**: title, description, start/end (stored UTC, entered/shown in `app.business_timezone`),
   location text, optional video link (https only), organiser, attendees, status scheduled|cancelled.
2. **Attendees**: staff (users), contacts (with email), or any external email. Each has a private
   `response_token`, a response (pending|accepted|declined), invited_at.
3. **Calendar page** `/meetings` (permission `meetings.view`): month grid + "upcoming" list; shows the
   signed-in user's meetings plus their open task due dates (read-only, links to the board). Users with
   `meetings.manage_all` can switch to "Everyone".
4. **Create / edit / cancel**: `meetings.create` for own meetings; organiser or `meetings.manage_all`
   may edit/cancel. `meetings.manage_all` may schedule on behalf of another staff member.
5. **Conflicts**: the form warns (never blocks) when a staff attendee already has an overlapping meeting.
6. **Availability**: "In a meeting until HH:MM" is *derived* from meetings in progress and shown next to the
   person's manual presence (available/busy/away). Manual presence is never overwritten.
7. **Invites**: email with `.ics` (METHOD:REQUEST, SEQUENCE bumps on change, METHOD:CANCEL on cancel),
   Accept / Decline links. Links open a confirm page (GET shows, POST records) so mail scanners that
   prefetch links cannot answer for the recipient. Staff can also respond in-app.
8. **Reminders**: command `meetings:remind` (every minute): 24h and 10min before, to organiser + non-declined
   attendees. Flag `meetings.reminders_enabled` (env `MEETINGS_REMINDERS`), default OFF until invite mail is
   verified on a real mailbox. Each reminder is stamped so it cannot repeat.
9. **Google / Teams (free)**: video-link field with "Create Google Meet" (opens meet.google.com/new) and
   "Create Teams meeting" buttons that open in a new tab; the organiser pastes the link back. A meeting
   shows "Add to Google Calendar", "Add to Outlook" deep links and a `.ics` download.

## Out of scope (later releases)
Video rooms (own WebRTC + coturn), recording, AI transcript/summary, auto-creating Meet/Teams rooms via
OAuth, recurring meetings, call routing that treats "in a meeting" as busy.

## Security / safety contracts
* video_url must be `https://`; rendered with `rel="noopener noreferrer"` and never as `javascript:`.
* Response tokens are 40-char random, unique, only ever shown to their own recipient; rate-limited.
* Mail is queued; a failed invite is recorded on the attendee (`invite_error`) and shown to the organiser, never
  silent. Needs a real `MAIL_MAILER` to actually deliver (same as other app mail).
* Every Livewire action re-checks permission and ownership server side.
* Attendee email lines are validated; max 50 attendees per meeting; header injection impossible (addresses
  are validated emails, ICS text is escaped and line-folded).
