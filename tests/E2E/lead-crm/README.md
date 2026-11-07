# Lead CRM end-to-end test rig

A deterministic fake of Apollo and Gemini so the whole lead pipeline can be driven in a real
browser (Playwright, or any browser driver) without spending credits or calling real services.

## Start it

```bash
# 1. The fake services (port 9101)
node tests/E2E/lead-crm/mock-services.cjs

# 2. The app, pointed at them, with jobs run immediately and no websocket
APOLLO_BASE_URL=http://127.0.0.1:9101/api/v1 \
GEMINI_BASE_URL=http://127.0.0.1:9101/gemini \
GEMINI_API_KEY=mock-gemini-key-123456 AI_PROVIDER=gemini \
QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log \
php -d opcache.enable=0 -S 127.0.0.1:8001 -t public server.php
```

Then sign in as an admin and save the Apollo key `mock-apollo-key-123456` on **Lead Sources**.
Use a local database: the run creates leads, profiles and settings.

## What the fakes do

- **Apollo search**: 60 people, 25 per page, titles cycling CEO, Founder, CTO, Marketing Intern,
  Sales Associate, Managing Director. Every 7th person has no email. Person 5 has a title that
  tries to close the prompt fence. Wrong key gives 401.
- **Apollo reveal**: `person<N>@example.test`. Every 11th person returns no email. Every 13th
  returns `Person1@Example.test`, to exercise duplicate handling.
- **Gemini**: turns a brief into filters, and scores a lead 90 (CEO, Founder, Managing Director),
  60 (CTO) or 15 (anything else) from the title in the prompt.
- `GET /__calls` returns call counts, the last search URL and every AI prompt; `POST /__reset`
  clears them. Use these to assert on cost (AI calls, reveals) as well as on the screens.

## Expected numbers for one full run of 60 leads

17 leads are rejected by the free gates (8 without email, 10 interns, 1 both), so the AI is called
43 times. 31 credits are spent (34 reveal calls, 3 of which return no email). A run handles at most
20 leads, so a first run on 25 leads leaves 5 waiting.

## Outreach, autonomy and replies (phases 3 and 4)

Two more helpers, plus the real background processes:

```bash
node tests/E2E/lead-crm/smtp-sink.cjs                 # SMTP on 2525 + HTTP list of every email sent on 9102
php artisan reverb:start                              # live alerts (set REVERB_SERVER_PORT/REVERB_PORT if 8080 is blocked)
php artisan queue:work --queue=default,mail-send,mail-sync
php artisan schedule:work                             # run with MAIL_CLIENT_ENABLED=false so the sync does not hit the fake mailbox
```

Point a connected mailbox at the sink (smtp_host 127.0.0.1, smtp_port 2525, no encryption), set the AI to the
fake Gemini (`GEMINI_BASE_URL=http://127.0.0.1:9101/gemini`), and mail the alerts to the sink too
(`MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=2525`). The fake Gemini now also writes outreach emails and
classifies replies from keywords (unsubscribe, lawyer, away until, no thanks, a question, interested).

Pretend a prospect answered (stores an inbound email and fires the same `MailReceived` event as the real sync):

```bash
php tests/E2E/lead-crm/simulate-reply.php prospect@example.test "Yes, tell me more about a demo."
```
