/**
 * Loads Cloudflare Turnstile only when the lead form it protects is about
 * to be used: when the form comes near the viewport or gets focus
 * (milestone 11). /prices is a long, full-page cached page whose question
 * form is far below the fold, so most visitors never download the widget.
 *
 * The script is created by our own nonced bundle and carries the page's
 * nonce, so the strict CSP ('nonce-…' 'strict-dynamic') is unchanged.
 * Turnstile renders the .cf-turnstile element itself once loaded.
 *
 * <div class="cf-turnstile" x-data="turnstile" data-src="https://challenges.cloudflare.com/turnstile/v0/api.js" data-sitekey="…"></div>
 */
export default () => ({
    init() {
        const form = this.$root.closest('form') ?? this.$root;
        const src = this.$root.dataset.src;
        let observer = null;

        const load = () => {
            observer?.disconnect();
            form.removeEventListener('focusin', load);

            if (!src || document.querySelector('script[data-turnstile]')) {
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.defer = true;
            script.dataset.turnstile = '';

            const nonce = document.querySelector('script[nonce]')?.nonce;

            if (nonce) {
                script.nonce = nonce;
            }

            document.head.appendChild(script);
        };

        form.addEventListener('focusin', load);

        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    load();
                }
            }, { rootMargin: '400px 0px' });
            observer.observe(form);
        } else {
            load();
        }
    },
});
