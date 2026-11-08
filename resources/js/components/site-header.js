/**
 * Site header: solid background once the page scrolls (overlay mode) and a
 * keyboard-friendly mobile menu using the disclosure pattern: Escape closes
 * it and returns focus to the toggle, tabbing out of the header closes it,
 * and it closes when the viewport reaches the desktop breakpoint.
 *
 * Markup only refers to properties and argument-free methods, so this keeps
 * working with Alpine's CSP build (no inline expressions to evaluate).
 */
export default function siteHeader() {
    return {
        open: false,
        scrolled: false,
        desktopQuery: null,

        init() {
            const onScroll = () => {
                this.scrolled = window.scrollY > 40;
            };
            // Read the scroll position on the next frame, not while Alpine is
            // still applying bindings: reading it then forces a synchronous
            // layout inside Alpine's start-up task (milestone 11, TBT).
            requestAnimationFrame(onScroll);
            window.addEventListener('scroll', onScroll, { passive: true });

            this.desktopQuery = window.matchMedia('(min-width: 64rem)');
            this.desktopQuery.addEventListener('change', (event) => {
                if (event.matches) {
                    this.close();
                }
            });
        },

        get solid() {
            return this.scrolled || this.open;
        },

        get closed() {
            return !this.open;
        },

        // Overlay headers start transparent over the hero.
        get overlayClass() {
            return this.solid
                ? 'border-b border-line bg-earth/95'
                : 'border-b border-transparent';
        },

        get expanded() {
            return this.open ? 'true' : 'false';
        },

        get toggleLabel() {
            return this.open ? 'Close menu' : 'Open menu';
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
            // Disclosure pattern: focus stays on the toggle; the menu follows it
            // in DOM order, so the next Tab lands on the first link.
            document.documentElement.style.overflow = 'hidden';
        },

        close(returnFocus = true) {
            if (!this.open) {
                return;
            }
            this.open = false;
            document.documentElement.style.overflow = '';
            if (returnFocus === true) {
                this.$refs.toggle?.focus();
            }
        },

        onEscape() {
            this.close();
        },

        onFocusOut(event) {
            if (this.open && !this.$root.contains(event.relatedTarget)) {
                this.close(false);
            }
        },
    };
}
