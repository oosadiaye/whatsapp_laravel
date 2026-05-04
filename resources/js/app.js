import './bootstrap';

import Alpine from 'alpinejs';

// Idempotent Alpine bootstrap.
//
// Why the guard: Livewire 4 ships its own Alpine bundle internally and starts
// it via @livewireScripts (which runs synchronously at end of <body>). Our
// @vite-loaded module is `<script type="module">`, which is deferred to after
// document parse — so on Livewire pages, window.Alpine is ALREADY defined
// when this module runs. Without the guard, we'd register a second Alpine
// instance and the browser console shows "Detected multiple instances of
// Alpine running" plus subtle x-data scope conflicts.
//
// Why we still bundle Alpine: pages that don't render any Livewire component
// (e.g. /contacts/import, /instances index, /groups index) never load
// Livewire's Alpine, so we MUST start one ourselves or every x-cloak / @click /
// x-data on those pages silently breaks.
if (typeof window.Alpine === 'undefined') {
    window.Alpine = Alpine;
    Alpine.start();
}
