import './bootstrap';

import Alpine from 'alpinejs';

// Expose globally so x-data="customFunction()" lookups work, and start the
// reactivity engine. Livewire v4 detects an existing window.Alpine and uses
// it instead of bootstrapping its own — no "multiple instances" warning.
//
// Without this import, pages that don't render any Livewire component
// (e.g. /contacts/import, /instances index, /groups index) have no Alpine
// at all, so every x-cloak / @click / x-data on those pages silently breaks.
window.Alpine = Alpine;
Alpine.start();
