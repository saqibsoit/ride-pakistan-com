/**
 * Lead forms on full-page cached pages (/prices) can't get per-visit
 * values from the server, so this fills their hidden attribution fields
 * from the current page: UTM tags and click ids from the query string,
 * the page URL and an external referrer. Nothing is stored; the values
 * are only sent if the visitor submits the form. (With consent, the
 * first-touch cookie takes precedence on the server.)
 */
const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];

export default function fillLeadAttribution() {
    const forms = document.querySelectorAll('form[data-lead-attribution]');

    if (forms.length === 0) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    let referrer = '';

    try {
        if (document.referrer && new URL(document.referrer).host !== window.location.host) {
            referrer = document.referrer;
        }
    } catch {
        referrer = '';
    }

    const values = { landing_url: window.location.href, referrer };
    KEYS.forEach((key) => {
        values[key] = params.get(key) || '';
    });

    forms.forEach((form) => {
        Object.entries(values).forEach(([name, value]) => {
            const input = form.querySelector(`input[type="hidden"][name="${name}"]`);
            if (input && !input.value && value) {
                input.value = value.slice(0, name === 'landing_url' || name === 'referrer' ? 2000 : 255);
            }
        });
    });
}
