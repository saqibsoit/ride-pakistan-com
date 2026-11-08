// Copy a value to the clipboard: <div x-data="copyButton" data-value="...">.
// The value stays visible and selectable, so it works without JavaScript;
// the button is hidden until Alpine runs (x-cloak). The status text is
// announced politely ("Copied").
export default () => ({
    copied: false,
    timer: null,

    async copy() {
        const value = this.$root.dataset.value ?? '';

        try {
            await navigator.clipboard.writeText(value);
            this.copied = true;
        } catch {
            this.copied = false;
            return;
        }

        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            this.copied = false;
        }, 2000);
    },

    get label() {
        return this.copied ? 'Copied' : 'Copy';
    },

    // For the polite live region next to the button (data-label names the value).
    get announcement() {
        return this.copied ? `${this.$root.dataset.label ?? 'Value'} copied` : '';
    },
});
