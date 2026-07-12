# Call Workspace — operator guide

The **Call Workspace** (`/workspace`) is the agent's one-stop call view: the
recent-call queue/history on the left, and a per-call intelligence panel on the
right with an AI summary + key points + transcript, a recording player, a
context brief (engagement, recent messages, prior calls), and an append-only
notes timeline.

The AI half is **off by default** because recording customer calls has consent
and retention obligations. This guide is how you turn it on and verify it.

> The workspace page, notes, history, and context brief work **without** any of
> the setup below. Only the recording + AI summary need enabling.

---

## 1. How it works (the pipeline)

```
 live call (browser softphone)
   → call-recorder.js taps the peer connection (remote leg + agent mic),
     mixes + records with MediaRecorder
   → on hangup, POST /calls/{id}/recording  (private disk, ai_status = pending)
   → TranscribeCallRecording job:
       webm? → AudioTranscoder remuxes to ogg via ffmpeg (Gemini won't take webm)
             → GeminiTranscriptionService: audio → transcript + summary + key points
       → ai_status = completed | failed
   → panel polls ai_status and renders the result
```

`ai_status` on each call drives the panel:

| status        | meaning                                                        |
|---------------|----------------------------------------------------------------|
| `none`        | no recording captured yet                                      |
| `pending`     | recording uploaded, queued for analysis                        |
| `processing`  | Gemini call in flight                                          |
| `completed`   | transcript + summary + key points available                   |
| `failed`      | analysis errored (see `ai_error`); **Re-analyse** to retry     |
| `unavailable` | recording exists but no Gemini key / recording was pruned      |

---

## 2. Enable it

### 2a. Gemini API key (the AI half)
Get a key at <https://aistudio.google.com/apikey>, then in `.env`:

```env
GEMINI_API_KEY=your-key-here
# GEMINI_MODEL=gemini-2.0-flash        # default; multimodal, fast, cheap
```

Without a key, recordings are stored but stay `unavailable` (no analysis).

### 2b. Recording flag (consent gate)
```env
VOICE_CALL_RECORDING_ENABLED=true
```

**Only flip this once you play/show a "this call may be recorded" notice.** The
browser recorder and the `/calls/{id}/recording` upload endpoint both refuse to
run while this is `false`.

### 2c. ffmpeg (required for Chrome)
Chrome's recorder only produces `audio/webm`, which Gemini rejects. `ffmpeg`
remuxes it to `ogg` first. Install ffmpeg and make sure it's on `PATH` (or set
`FFMPEG_PATH=/full/path/to/ffmpeg`). Verify:

```bash
ffmpeg -version
```

- **Firefox / Safari** record Gemini-native `ogg` / `mp4` and skip ffmpeg.
- **No ffmpeg + Chrome** → the webm is sent as-is and Gemini likely rejects it
  (`ai_status = failed`). Install ffmpeg, then hit **Re-analyse** on the call.

### 2d. Retention (optional but recommended)
```env
VOICE_RECORDING_RETENTION_DAYS=30   # delete raw audio after 30 days; 0 = keep forever
# VOICE_RECORDING_MAX_KB=25600      # per-recording upload cap (~25 MB)
```

`calls:prune-recordings` runs daily and deletes audio past the window while
**keeping the transcript/summary**. Requires the scheduler to be running (it
already is if `campaigns:dispatch-scheduled` etc. run — `php artisan schedule:work`
in dev, or the system cron entry in prod).

After editing `.env`: `php artisan config:clear`.

---

## 3. Verify end-to-end

1. Confirm the queue worker + scheduler are running (Horizon / `queue:work`,
   and `schedule:work`).
2. Open `/workspace`. The amber "recording is off" banner should be **gone**
   once `VOICE_CALL_RECORDING_ENABLED=true` and a `GEMINI_API_KEY` is set.
3. Make a real call (inbound or outbound via the softphone) and hang up.
4. Open that call in the panel. Watch `ai_status`:
   - the spinner ("Analysing the call…") means `pending`/`processing`,
   - then the summary + key points + transcript appear (`completed`),
   - a recording player is present.
5. If it lands on **failed**, read `ai_error`, fix the cause (usually missing
   ffmpeg or a Gemini quota/key issue), then click **Re-analyse call**.
6. Add a note — it should log with your name + timestamp and persist.

---

## 4. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| Banner still says "recording is off" | `VOICE_CALL_RECORDING_ENABLED` not `true`, or `config:clear` not run |
| `ai_status` stuck at `pending`/`processing` | queue worker not running |
| `ai_status = failed`, error mentions HTTP 4xx | Chrome webm + no ffmpeg → install ffmpeg, Re-analyse. Or bad/over-quota key |
| `ai_status = unavailable` | no `GEMINI_API_KEY`, or the recording was pruned by retention |
| No recording player, `ai_status = none` | recording didn't capture — browser blocked mic, or the SDK didn't expose a peer connection; check the browser console for `[BQ recorder]` |
| Summary is thin / empty | short or silent call; Gemini returns empty on unintelligible audio |

Recordings live on the **private** disk (`storage/app/private/call-recordings/`)
and only stream via `GET /calls/{id}/recording`, which enforces the same
per-call access check as every other call action. They are never web-public.

---

## 5. Security & compliance notes

- **Consent** is your responsibility — a "this call may be recorded" notice
  before recording begins. The flag is off by default precisely so this is a
  deliberate decision.
- **Retention** — set `VOICE_RECORDING_RETENTION_DAYS` to match your policy.
  Pruning removes the audio but keeps the transcript; if your policy requires
  the transcript gone too, extend `PruneCallRecordings` to null those columns.
- **The Gemini key** is sent in the `x-goog-api-key` header and never appears in
  logs, URLs, or the `ai_error` shown in the UI.
- **Access** — the workspace needs `conversations.view_all` or
  `conversations.view_assigned`; recording upload + notes need
  `conversations.reply`; every per-call action re-checks access server-side.

---

## Related
- `docs/VOICE-ROADMAP.md` — where call recording sat on the telephony roadmap.
- `docs/AFRICASTALKING-VERIFICATION.md` — verifying the underlying softphone the
  recorder taps.
