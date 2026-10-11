# Vendored browser assets

These files are committed deliberately and served same-origin. Do not replace them
with a CDN `<script>`/`<link>` — the production CSP is same-origin for scripts and
media, and the supply chain stays pinned + auditable.

## `africastalking-1.0.7.js` — PATCHED, do not re-vendor blindly

The Africa's Talking WebRTC softphone SDK (`africastalking-client@1.0.7`). It is
loaded from here rather than imported via npm, because the package has a
module-cache state-leak bug where a static `import` retains broken WebSocket
state across reconnects (see `resources/js/outbound-call.js`).

**Local patch applied:** upstream hardcodes its ringback/dial tones to
`https://res.cloudinary.com/at-voice/...`. Those URLs are rewritten to the
same-origin files in `at-audio/` below:

| Upstream URL | Patched to |
|---|---|
| `…/v1558426103/AT-voice-client-sdk/ringing_au0d1x.mp3` | `/vendor/at-audio/ringing.mp3` |
| `…/v1558426588/AT-voice-client-sdk/dialing_g4tn6r.mp3` | `/vendor/at-audio/dialing.mp3` |

Without the patch the production CSP (`media-src 'self' blob: data:`) blocks both,
and the agent hears no ringback tone while an outbound call connects. (Call audio
itself is unaffected — `media-src` governs `<audio>`/`<video>` loading, not the
WebRTC peer connection.)

**If you ever upgrade or re-download this SDK, re-apply the two URL rewrites.**
`tests/Feature/Calls/VendoredAtSdkTest.php` fails if an external host reappears.

## `at-audio/*.mp3`

The two upstream tones, downloaded once from the Cloudinary URLs above so they can
be served same-origin.

## `chart.min.js`

Chart.js, self-hosted for the same CSP reason.
