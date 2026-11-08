import { consentState, setConsent } from '../tracking';

/**
 * Cookie banner for non-essential tracking (Meta Pixel, GA4). Shown until
 * the visitor answers; "Cookie settings" in the footer opens it again
 * (window event `rp-consent-open`). Accept and Decline carry equal weight.
 * Argument-free methods only, for Alpine's CSP build.
 */
export default function consentBanner() {
    return {
        open: false,

        init() {
            this.open = consentState() === null;
            window.addEventListener('rp-consent-open', () => {
                this.open = true;
                this.$nextTick(() => this.$refs.accept?.focus());
            });
        },

        accept() {
            setConsent(true);
            this.open = false;
        },

        decline() {
            setConsent(false);
            this.open = false;
        },
    };
}
