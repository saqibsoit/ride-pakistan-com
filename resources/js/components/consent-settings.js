/** Footer "Cookie settings" button: reopens the consent banner. */
export default function consentSettings() {
    return {
        reopen() {
            window.dispatchEvent(new CustomEvent('rp-consent-open'));
        },
    };
}
