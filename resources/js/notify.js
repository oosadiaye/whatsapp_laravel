// In-page toasts for the `notify` event that Livewire components dispatch
// ($this->dispatch('notify', message: '...', type: 'error')). Rendered by the host in the app layout.
const ERROR_MS = 12000;
const INFO_MS = 5000;

window.bqNotify = () => ({
    toasts: [],

    push(detail) {
        const d = detail ?? {};
        // Livewire passes a bare string, a list, or a named-argument object depending on the call.
        const text = typeof d === 'string' ? d : (d.message ?? d.text ?? d[0] ?? '');
        if (! text) { return; }

        const type = typeof d === 'object' && d.type ? d.type : 'info';
        const id = Date.now() + Math.random();
        this.toasts.push({ id, text, type });
        setTimeout(() => this.dismiss(id), type === 'error' ? ERROR_MS : INFO_MS);
    },

    dismiss(id) {
        this.toasts = this.toasts.filter((t) => t.id !== id);
    },
});
