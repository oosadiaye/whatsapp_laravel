# Design system — "Signal Desk"

How the UI is themed, and the rules for adding to it. The visual reference is the
design canvas "BlastIQ UI Redesign" (dashboard, inbox with a live call, style guide).

## Principles

1. **One signal per meaning.** Green = messaging, primary actions, success. Blue = calls,
   AI drafts, information. Amber = needs attention. Red = missed, failed, destructive.
   Colour is never decoration.
2. **Tokens, not hex codes.** Views use roles (`bg-white`, `text-gray-500`, `bg-brand`), never
   hex values, so dark mode is a variable swap and not a rewrite.
3. **Borders first, shadows last.** Depth comes from layers and hairlines. Only the live call
   floats.
4. **Numbers are tabular.** Timers, counts and phone numbers use `font-data` / `tabular-nums`.
5. **Four radii.** 8 (inputs), 12 (buttons, `rounded-lg`), 16 (inner panels, `rounded-xl`),
   20 (cards, `rounded-2xl`), plus pill (`rounded-full`).

## How theming works

Everything flows from CSS variables in `resources/css/app.css`:

| Variable | Meaning |
|---|---|
| `--gray-50 … --gray-900` | Neutral scale. **Low numbers are surfaces, 900 is ink.** The scale flips in dark mode, so `bg-gray-50` is the lightest surface in light mode and the darkest in dark mode. |
| `--surface` | Cards, panels, inputs. `bg-white` is remapped to this. |
| `--ground` | Page background. |
| `--brand`, `--brand-hover`, `--brand-soft`, `--brand-text`, `--brand-ink` | The green, its hover, its tint, text-on-light, and the ink to use *on* a solid brand fill. |
| `--voice`, `--voice-soft` | The blue. |
| `--danger` | Red, for charts and canvases that cannot use a class. |
| `--shadow-sm/md/lg` | Elevation. |

`tailwind.config.js` maps `gray-*` and `brand` onto those variables and aliases the old hue
names onto four families: `green/emerald/teal/lime → brand`, `blue/indigo/violet/purple/sky/cyan →
voice`, `amber/yellow/orange → amber`, `red/rose/pink → red`. Existing markup such as `bg-emerald-50`
or `text-indigo-600` therefore lands on the right family without edits.

A generated plugin in the same file remaps tints in dark mode (`bg-red-50` becomes a low-alpha
red wash, `text-green-700` becomes the family's light shade), so pale tints never glare on a dark
surface.

### Dark mode

* `<html class="dark">` switches the variables. A nonce'd inline script in the `<head>` of both
  layouts applies the saved choice (or the OS preference) before first paint.
* `resources/js/theme.js` exposes `window.bqTheme.toggle()` and keeps the page in step with the OS
  while the user has not chosen. The choice is stored in `localStorage` (`bq-theme`).
* Charts read the same variables (`getComputedStyle`) and redraw on the `bq-theme-changed`
  event — see `resources/views/dashboard.blade.php`.

## Rules for new UI

* **Never write a hex colour or `bg-[#…]`.** Use `bg-brand`, `text-brand-text`, `border-brand`,
  `bg-brand-soft`, or a `gray-*` / hue-family class.
* **Text on a solid brand fill** uses `text-brand-ink` (white in light, near-black in dark).
* **Small text must clear 4.5:1.** Use `text-gray-500` or darker for secondary text, never
  `text-gray-400` (it is for placeholders and decorative icons only).
* **Buttons:** use `<x-primary-button>`, `<x-secondary-button>`, `<x-danger-button>`; they are
  sentence-case, 12px radius, with a visible focus ring.
* **Headings and big numbers** use `font-display`; numerals and timers use `font-data`.
* **Do not use `bg-gray-900` for scrims.** In dark mode gray-900 is the light ink. A
  `bg-gray-900/xx` overlay is already remapped to black, but prefer `bg-black/50`.
* **Anything the browser draws itself** (canvas, inline SVG `fill`) should read the variables:
  `rgb(var(--brand))`.

## Typography

Instrument Sans (body, `font-sans`), Bricolage Grotesque (`font-display`), JetBrains Mono
(`font-data`) — loaded in one request from `fonts.bunny.net`, the host already allowed by the CSP.

## Not part of this system (yet)

* The icon-only **rail** navigation from the mockups. The app has ~25 destinations, so the
  sidebar keeps labels; it uses the new tokens, logo tile and active-pill treatment.
* A horizontal live-call **dock**. The call panel keeps its existing Alpine bindings and takes the
  new tokens; changing its layout is a separate, call-testing task.
