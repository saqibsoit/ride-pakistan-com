/**
 * Consent-gated tracking (PLAN milestone 9).
 *
 * The layout renders <script type="application/json" id="rp-tracking">
 * only when Meta Pixel or GA4 is configured. Nothing below runs a vendor
 * script, sets a tracking cookie or sends a request until the visitor
 * accepts in the cookie banner (cookie `rp_consent=granted`).
 *
 * On consent:
 *  - writes the first-touch cookie `rp_ft` (if absent) from the page the
 *    visitor landed on in this visit: UTM tags, fbclid, gclid, landing URL,
 *    external referrer and time;
 *  - loads the Pixel and gtag with the page's CSP nonce;
 *  - fires PageView and the queued conversion events (Lead,
 *    InitiateCheckout, Purchase) with event ids shared with Meta CAPI.
 * Later events (Livewire status page) arrive as a `rp-track` window event.
 */

const CONSENT_COOKIE = 'rp_consent';
const FIRST_TOUCH_COOKIE = 'rp_ft';
const CONSENT_DAYS = 180;
const FIRST_TOUCH_DAYS = 90;
const ATTRIBUTION_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];

// Captured at load, before any navigation, so accepting later on the same
// page still records where the visit started.
const landing = {
    url: window.location.href,
    referrer: externalReferrer(),
};

let config = null;
let started = false;
const pending = [];

function readCookie(name) {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

function writeCookie(name, value, days) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${days * 86400}; Path=/; SameSite=Lax${secure}`;
}

function externalReferrer() {
    try {
        if (document.referrer && new URL(document.referrer).host !== window.location.host) {
            return document.referrer;
        }
    } catch {
        // Unparseable referrer: ignore it.
    }

    return null;
}

export function consentState() {
    return readCookie(CONSENT_COOKIE);
}

function writeFirstTouch() {
    if (readCookie(FIRST_TOUCH_COOKIE)) {
        return;
    }

    const params = new URL(landing.url).searchParams;
    const data = { landing_url: landing.url.slice(0, 2000), ts: Math.floor(Date.now() / 1000) };

    ATTRIBUTION_KEYS.forEach((key) => {
        const value = params.get(key);
        if (value) {
            data[key] = value.slice(0, 255);
        }
    });

    if (landing.referrer) {
        data.referrer = landing.referrer.slice(0, 2000);
    }

    writeCookie(FIRST_TOUCH_COOKIE, JSON.stringify(data), FIRST_TOUCH_DAYS);
}

function loadScript(src, nonce) {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    if (nonce) {
        script.nonce = nonce;
    }
    document.head.appendChild(script);
}

function startMeta(pixelId, nonce) {
    /* eslint-disable */
    const fbq = function () {
        fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments);
    };
    if (!window._fbq) window._fbq = fbq;
    fbq.push = fbq;
    fbq.loaded = true;
    fbq.version = '2.0';
    fbq.queue = [];
    window.fbq = window.fbq || fbq;
    /* eslint-enable */

    loadScript('https://connect.facebook.net/en_US/fbevents.js', nonce);
    window.fbq('init', pixelId);
    window.fbq('track', 'PageView');
}

function startGa4(measurementId, nonce) {
    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        // gtag.js expects the arguments object itself.
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer.push(arguments);
    };
    loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`, nonce);
    window.gtag('js', new Date());
    window.gtag('config', measurementId);
}

const GA4_NAMES = { Lead: 'generate_lead', InitiateCheckout: 'begin_checkout', Purchase: 'purchase', Contact: 'contact' };

const fired = new Set();

function fire(event) {
    // Once per event id (a stashed event can also be on the reloaded page).
    if (!event || !event.name || !event.id || fired.has(event.id)) {
        return;
    }

    fired.add(event.id);

    const money = event.value === undefined ? {} : { value: event.value, currency: event.currency || 'PKR' };

    if (config.metaPixelId && window.fbq) {
        window.fbq('track', event.name, money, { eventID: event.id });
    }

    if (config.ga4Id && window.gtag && GA4_NAMES[event.name]) {
        const params = { ...money };
        if (event.reference) {
            params.transaction_id = event.reference;
        }
        window.gtag('event', GA4_NAMES[event.name], params);
    }
}

function start() {
    if (started || !config) {
        return;
    }
    started = true;

    writeFirstTouch();

    if (config.metaPixelId) {
        startMeta(config.metaPixelId, config.nonce);
    }

    if (config.ga4Id) {
        startGa4(config.ga4Id, config.nonce);
    }

    (config.events || []).forEach(fire);
    pending.splice(0).forEach(fire);
}

/**
 * Called by the consent banner. The page's CSP lists the vendor origins
 * only for visitors who have consented (App\Support\Csp\PolicyBuilder), so
 * accepting reloads the page: the new response carries a policy that
 * allows Pixel and GA4. This page's queued events and the landing data
 * are kept across the reload.
 */
export function setConsent(granted) {
    writeCookie(CONSENT_COOKIE, granted ? 'granted' : 'denied', CONSENT_DAYS);

    if (granted) {
        if (config) {
            writeFirstTouch();
            stashEvents([...(config.events || []), ...pending]);
            window.location.reload();
        }

        return;
    }

    // Withdrawn: remove what we and the vendors set on our domain. Scripts
    // already running on this page stop with the next page load.
    document.cookie.split('; ').forEach((part) => {
        const name = part.split('=')[0];
        if (name === FIRST_TOUCH_COOKIE || name === '_fbp' || name === '_fbc' || name.startsWith('_ga')) {
            document.cookie = `${name}=; Max-Age=0; Path=/`;
            document.cookie = `${name}=; Max-Age=0; Path=/; Domain=.${window.location.hostname.replace(/^www\./, '')}`;
        }
    });
}

export function initTracking() {
    const element = document.getElementById('rp-tracking');

    if (!element) {
        return;
    }

    try {
        config = JSON.parse(element.textContent || '{}');
    } catch {
        return;
    }

    // The browser hides the nonce attribute but keeps the property.
    config.nonce = element.nonce || '';

    window.addEventListener('rp-track', (event) => {
        // Livewire's dispatch('rp-track', tracking: {...}) arrives as { tracking }.
        const raw = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        const detail = raw && raw.tracking ? raw.tracking : raw;
        if (started) {
            fire(detail);
        } else {
            pending.push(detail);
        }
    });

    // Contact links (the WhatsApp button): counted only while tracking runs,
    // which needs consent. A click before consent is never queued or sent.
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-track="contact"]') : null;
        if (link && started) {
            fire({ name: 'Contact', id: `contact-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` });
        }
    });

    if (consentState() === 'granted') {
        config.events = [...takeStashedEvents(), ...(config.events || [])];
        start();
    }
}

const STASH_KEY = 'rp_pending_events';

function stashEvents(events) {
    try {
        if (events.length > 0) {
            window.sessionStorage.setItem(STASH_KEY, JSON.stringify(events.slice(0, 20)));
        }
    } catch {
        // Storage blocked: the events are lost, nothing else breaks.
    }
}

function takeStashedEvents() {
    try {
        const raw = window.sessionStorage.getItem(STASH_KEY);
        window.sessionStorage.removeItem(STASH_KEY);
        const events = raw ? JSON.parse(raw) : [];

        return Array.isArray(events) ? events : [];
    } catch {
        return [];
    }
}
