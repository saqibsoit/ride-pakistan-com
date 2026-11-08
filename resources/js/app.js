// Alpine's CSP build: no eval, so the strict CSP needs no 'unsafe-eval'
// (PLAN §7). Components are registered by name (components/register.js).
import Alpine from '@alpinejs/csp';
import registerComponents from './components/register';
import fillLeadAttribution from './lead-attribution';
import { initTracking } from './tracking';

// Emit fonts and brand images into the Vite manifest so Blade can reference
// them with Vite::asset() (e.g. the display font preload). No JS cost.
import.meta.glob('../fonts/*.woff2');

// Plain Blade pages boot Alpine from here. Pages that render Livewire
// components load resources/js/livewire.js instead, which uses the Alpine
// bundled with Livewire, so Alpine never loads twice on one page.
registerComponents(Alpine);

window.Alpine = Alpine;
Alpine.start();

// Consent-gated Pixel/GA4 (no-op unless configured and accepted) and
// per-visit attribution for lead forms on cached pages.
// In a later task, so the start-up task stays short (milestone 11, TBT).
// Neither is needed for the first paint: tracking waits for consent and
// attribution only matters when a lead form is sent.
setTimeout(() => {
    initTracking();
    fillLeadAttribution();
}, 0);
