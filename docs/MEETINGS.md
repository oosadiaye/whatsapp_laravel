# Meetings & calendar

A team calendar at **/meetings** (sidebar → Calendar → Meetings). Zero running cost: BlastIQ does not carry
video; each meeting holds a pasted Google Meet / Microsoft Teams / any https link.

## What it does
- Schedule a meeting (title, date, start/end in `APP_BUSINESS_TIMEZONE`, place, notes, video link).
- Invite colleagues (checkboxes), contacts (search, must have an email) and any email address.
- Each invitee gets an email with a `.ics` file and Accept / Decline buttons. Answers are recorded on a public
  confirm page (no login needed; the link carries a private 40-character token). Decline emails the organiser.
- Rescheduling resets answers and re-sends; cancelling sends a cancellation (`METHOD:CANCEL`).
- The calendar also shows your open task due dates (read-only) and who is free right now.
- "Add to calendar": Google, Outlook/Teams deep links and a `.ics` download.
- Clash warning (never blocks) when an invited colleague already has a meeting at that time.

## Permissions
| Permission | Who | Meaning |
|---|---|---|
| `meetings.view` | everyone | see the calendar (own meetings and tasks) |
| `meetings.create` | everyone | schedule/edit/cancel your own meetings |
| `meetings.manage_all` | super_admin, admin, manager | see "Everyone", edit/cancel others', schedule on someone's behalf |

After deploying run `php artisan db:seed --class=RolesAndPermissionsSeeder --force` so existing roles receive the
new permissions (deploy.sh already seeds roles; confirm in its output).

## Email
Invitations and reminders use the app's normal mailer (`MAIL_MAILER`). With `MAIL_MAILER=log` nothing is delivered.
Invitations are queued (`default` queue, handled by `blastiq-worker`). A failed invitation is shown to the
organiser in the meeting detail ("Invitation not delivered: …").

## Reminders
`php artisan meetings:remind` runs every minute from the scheduler but does nothing unless `MEETINGS_REMINDERS=true`.
It emails the organiser and everyone who has not declined, 24 hours and 10 minutes before the start. A meeting
set up or changed with less notice than a reminder's lead time never sends that reminder. Each reminder is
stamped so it cannot repeat.

## Google Calendar & Meet (free)
Settings -> **Google Calendar & Meet**: paste the OAuth Client ID and secret (steps are on the card; the exact
"Authorized redirect URI" to register is shown there). Then each person presses **Connect Google Calendar** (on Settings
or at the bottom of the Meetings page) and approves access. Buttons: **Sync now**, **Test connection** (a real read
against their calendar, with a plain-language reason when it fails), **Disconnect**.

- **Create:** new meetings default to "Create it in my Google Calendar with a Google Meet link". Google creates the Meet
  room and sends the invitations; BlastIQ sends none. If Google fails, the meeting is still saved, BlastIQ emails the
  invitations itself and the organiser is told why. A pasted video link is used instead of a new Meet.
- **Edit / cancel** of such a meeting is sent to Google (which updates the guests), with the same email fallback.
- **Import:** every 10 minutes (`google:sync`) events from Google (today -7 days to +90 days; all-day events are skipped)
  appear on the calendar as read-only copies with a Google/Teams/Zoom join link when one is found. They can only be
  changed in Google. Answers to meetings made in BlastIQ flow back. Declined or deleted events disappear.
- Reminders are not sent by BlastIQ for Google-held meetings (Google sends its own).
- Tokens are encrypted at rest. Revoked access shows "Needs reconnecting".
- Scope used: `calendar.events` (+ `openid email` to show which account is connected).
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in `.env` also work (Settings wins).
- Google Cloud: enable the **Google Calendar API**; use an *Internal* consent screen if everyone is in your Workspace,
  otherwise *External* (testing mode allows up to 100 test users; publishing needs Google's review).

## Not built yet (later releases)
Own video rooms (WebRTC + coturn), recording and AI transcript/summary, Microsoft Teams calendar sync (Teams links in Google events are shown, but rooms are not created in Teams),
recurring meetings, and treating "in a meeting" as busy for call routing.
