/**
 * Light/dark theme control.
 *
 * The first paint is handled by the inline bootstrap in the layout <head>; this
 * module exposes the toggle, keeps the page in step with the OS setting while the
 * user has not made an explicit choice, and re-applies the theme after Livewire's
 * wire:navigate swaps the page (see below).
 *
 * The only state is the `dark` class on <html> plus an optional saved choice in
 * localStorage ('bq-theme': 'light' | 'dark'). No saved choice = follow the OS.
 */

const STORAGE_KEY = 'bq-theme';
// Read live: wire:navigate can replace the <html> element, so never cache the node.
const root = () => document.documentElement;

function readSaved() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function apply(isDark) {
    root().classList.toggle('dark', isDark);
    window.dispatchEvent(new CustomEvent('bq-theme-changed', { detail: { dark: isDark } }));
    return isDark;
}

/** The theme the user should be seeing: their saved choice, else the OS preference. */
function resolveDark() {
    const saved = readSaved();
    if (saved) {
        return saved === 'dark';
    }

    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function isDark() {
    return root().classList.contains('dark');
}

/** Persist an explicit choice. Returns true when dark mode is now on. */
export function setTheme(mode) {
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        /* private mode: the choice just lasts for this page view */
    }

    return apply(mode === 'dark');
}

export function toggleTheme() {
    return setTheme(isDark() ? 'light' : 'dark');
}

// Follow the OS only until the user picks something.
if (typeof window.matchMedia === 'function') {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
        if (readSaved() === null) {
            apply(event.matches);
        }
    });
}

// wire:navigate replaces the whole <html> element with the next page's, which drops the
// `dark` class, and the <head> bootstrap only runs on a full page load. Without this,
// every sidebar click in dark mode would flip the page back to light. The event fires
// synchronously after the swap, before the browser paints, so there is no flash.
document.addEventListener('livewire:navigated', () => {
    root().classList.toggle('dark', resolveDark());
});

window.bqTheme = { isDark, setTheme, toggle: toggleTheme };
