/**
 * Multi-email field for the New meeting form: type or paste addresses, each becomes a removable chip.
 *
 * The parsing helper is pure so it can be unit-tested without a browser (tests/js/email-chips.test.mjs); the Alpine
 * component below is thin glue. The chips are mirrored into the Livewire property `externalEmails` as a
 * comma-separated string (no request until the form is saved). The server re-validates every address, so this is a
 * convenience, never the only check.
 *
 * Used as x-data="bqEmailChips()" — no config inside the attribute, because a stray double quote in an inline
 * x-data attribute silently truncates the whole component.
 */

const EMAIL = /^[^\s@,;<>()"']+@[^\s@,;<>()"']+\.[^\s@,;<>()"']+$/;

/** Split free text (commas, semicolons, spaces, newlines) into unique lower-case valid addresses and the rest. */
export function splitEmailInput(text) {
    const valid = [];
    const invalid = [];

    for (const token of String(text ?? '').split(/[\s,;]+/).filter(Boolean)) {
        const email = token.toLowerCase();
        if (!EMAIL.test(email)) {
            invalid.push(token);
        } else if (!valid.includes(email)) {
            valid.push(email);
        }
    }

    return { valid, invalid };
}

export const MAX_EMAILS = 50;

export function emailChips() {
    return {
        chips: [],
        draft: '',
        error: '',

        init() {
            this.chips = splitEmailInput(this.$wire.externalEmails).valid;
        },

        add(text) {
            const { valid, invalid } = splitEmailInput(text);
            const merged = [...this.chips];
            for (const email of valid) {
                if (!merged.includes(email)) merged.push(email);
            }

            this.error = '';
            if (merged.length > MAX_EMAILS) {
                this.error = `At most ${MAX_EMAILS} addresses.`;
            } else {
                this.chips = merged;
            }
            if (invalid.length > 0) {
                this.error = `Not an email address: ${invalid.slice(0, 3).join(', ')}`;
            }
            // Keep what could not be added in the box so it can be fixed instead of retyped.
            this.draft = invalid.join(' ');
            this.sync();
        },

        remove(index) {
            this.chips = this.chips.filter((_, i) => i !== index);
            this.error = '';
            this.sync();
        },

        sync() {
            this.$wire.externalEmails = this.chips.join(', ');
        },

        keydown(event) {
            const commit = ['Enter', ',', ';', ' ', 'Tab'];
            if (commit.includes(event.key) && this.draft.trim() !== '') {
                event.preventDefault();
                this.add(this.draft);
            } else if (event.key === 'Enter') {
                event.preventDefault(); // never submit the whole form from this field
            } else if (event.key === 'Backspace' && this.draft === '' && this.chips.length > 0) {
                this.remove(this.chips.length - 1);
            }
        },

        paste(event) {
            const text = event.clipboardData?.getData('text') ?? '';
            if (text.trim() === '') return;
            event.preventDefault();
            this.add(text);
        },

        blur() {
            if (this.draft.trim() !== '') this.add(this.draft);
        },
    };
}

if (typeof window !== 'undefined') {
    window.bqEmailChips = emailChips;
}
