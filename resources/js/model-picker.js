/**
 * Searchable OpenRouter model picker for Settings → AI Provider.
 *
 * The filtering/formatting helpers are pure so they can be unit-tested without a
 * browser (tests/js/model-picker.test.mjs); the Alpine component below is thin glue.
 *
 * Config travels in data-* attributes (data-name / data-value / data-url) rather than
 * inside x-data: a stray double quote in an inline x-data attribute silently truncates
 * the whole component.
 */

export function filterModels(models, { query = '', onlyFree = false, onlyVision = false } = {}) {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);

    return models.filter((m) => {
        if (onlyFree && !m.free) return false;
        if (onlyVision && !m.vision) return false;
        if (words.length === 0) return true;

        const haystack = `${m.name} ${m.id}`.toLowerCase();
        return words.every((w) => haystack.includes(w));
    });
}

export function formatPrice(model) {
    if (model.free) return 'Free';
    if (model.prompt_per_million === null || model.completion_per_million === null) return 'Price varies';

    return `$${model.prompt_per_million} in · $${model.completion_per_million} out per 1M tokens`;
}

export function describeModel(models, id) {
    if (!id) return '';
    return models.find((m) => m.id === id)?.name ?? id;
}

/** OpenRouter ids are always vendor/model; anything else is a leftover from another provider. */
export function initialSelection(value) {
    return typeof value === 'string' && value.includes('/') ? value : '';
}

export function formatContext(tokens) {
    if (!tokens) return '';
    return tokens >= 1000000 ? `${+(tokens / 1000000).toFixed(1)}M ctx` : `${Math.round(tokens / 1000)}k ctx`;
}

const RENDER_LIMIT = 200;

export function modelPicker() {
    return {
        models: [],
        loading: true,
        failed: false,
        open: false,
        query: '',
        onlyFree: false,
        onlyVision: false,
        name: '',
        value: '',
        url: '',

        init() {
            this.name = this.$el.dataset.name ?? '';
            this.value = initialSelection(this.$el.dataset.value);
            this.url = this.$el.dataset.url ?? '';
            this.load();
        },

        async load() {
            this.loading = true;
            this.failed = false;
            try {
                const res = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const body = await res.json().catch(() => ({ models: [] }));
                this.models = Array.isArray(body.models) ? body.models : [];
                this.failed = !res.ok || this.models.length === 0;
            } catch (e) {
                this.failed = true;
            } finally {
                this.loading = false;
            }
        },

        get filtered() {
            return filterModels(this.models, this);
        },

        get visible() {
            return this.filtered.slice(0, RENDER_LIMIT);
        },

        get hiddenCount() {
            return Math.max(0, this.filtered.length - RENDER_LIMIT);
        },

        get label() {
            return describeModel(this.models, this.value);
        },

        /** A pasted id that isn't in the list (new/unlisted model) can still be used. */
        get customCandidate() {
            const q = this.query.trim();
            return q.includes('/') && !q.includes(' ') && !this.models.some((m) => m.id === q) ? q : '';
        },

        price: formatPrice,
        context: formatContext,

        choose(id) {
            this.value = id;
            this.open = false;
            this.query = '';
        },
    };
}

if (typeof window !== 'undefined') {
    window.bqModelPicker = modelPicker;
}
