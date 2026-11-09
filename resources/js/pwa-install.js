// "Install BlastIQ" prompt. Chrome/Edge/Android fire `beforeinstallprompt`, which we hold
// and replay from our own modal; iOS Safari has no such event, so it gets Add-to-Home-Screen steps.
const SNOOZE_KEY = 'bq-install-snooze-until';
const SNOOZE_DAYS = 7;
const SHOW_DELAY_MS = 1500;

let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    window.dispatchEvent(new CustomEvent('bq-install-ready'));
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.dispatchEvent(new CustomEvent('bq-install-done'));
});

const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

const isIos = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const readSnooze = () => {
    try { return parseInt(localStorage.getItem(SNOOZE_KEY), 10) || 0; } catch (e) { return 0; }
};

window.bqInstallPrompt = function () {
    return {
        open: false,
        installed: isStandalone(),
        ios: isIos(),
        canPrompt: deferredPrompt !== null,

        // Offer the install button in the menu whenever the app is not installed yet and we can help.
        get available() { return ! this.installed && (this.canPrompt || this.ios); },

        init() {
            window.addEventListener('bq-install-ready', () => { this.canPrompt = true; this.autoOpen(); });
            window.addEventListener('bq-install-done', () => { this.installed = true; this.open = false; });
            window.addEventListener('bq-install-open', () => { if (this.available) { this.open = true; } });
            this.autoOpen();
        },

        autoOpen() {
            if (! this.available || readSnooze() > Date.now()) { return; }
            setTimeout(() => { if (this.available && ! this.open) { this.open = true; } }, SHOW_DELAY_MS);
        },

        async install() {
            if (! deferredPrompt) { return; }
            deferredPrompt.prompt();
            const choice = await deferredPrompt.userChoice;
            deferredPrompt = null;
            this.canPrompt = false;
            if (choice.outcome === 'accepted') { this.installed = true; }
            this.open = false;
        },

        later() {
            try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_DAYS * 86400000)); } catch (e) {}
            this.open = false;
        },
    };
};
