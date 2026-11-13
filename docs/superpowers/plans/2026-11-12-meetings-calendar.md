# Meetings & calendar — implementation plan (Release 1)

> Execute task by task, tests first. Spec: `docs/superpowers/specs/2026-11-12-meetings-calendar-design.md`.

1. **Schema + models**: migration `meetings`, `meeting_attendees`; models `Meeting`, `MeetingAttendee`
   (+factories); permissions `meetings.view|create|manage_all` in `RolesAndPermissionsSeeder`
   (all roles view+create; manage_all for admin/manager/super_admin only).
2. **IcsBuilder** (pure, unit-tested): escaping, folding at 75 octets, UTC times, METHOD REQUEST/CANCEL.
3. **MeetingService**: create/update/cancel, attendee sync, conflict lookup, invitations (queued mail,
   per-attendee error capture), response recording.
4. **Mail**: `MeetingInviteMail` (kind invite|update|cancel, .ics attachment), `MeetingReminderMail`,
   views in `resources/views/mail`.
5. **Public response page**: `MeetingResponseController` (GET confirm / POST record, throttled, guest layout).
6. **Livewire**: `Meetings\Calendar` (month grid, upcoming list, tasks overlay, detail panel, in-app respond,
   cancel) and `Meetings\MeetingForm` (create/edit with attendee picker, conflict warning, video buttons).
7. **Reminders**: `meetings:remind` command + schedule + config flag.
8. **Wiring**: routes, sidebar link with "today" badge, "in a meeting" derivation helper on `User`,
   deployment/docs note, memory.
9. **Verify**: full suite, browser check locally on :8001, code-reviewer pass, then commit (not pushed
   until the user says).
