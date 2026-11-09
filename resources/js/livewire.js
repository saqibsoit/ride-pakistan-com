// Livewire's CSP-safe build (Alpine CSP inside): no eval on public pages
// (PLAN §7). The admin panel keeps Filament's own Livewire script.
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.csp.esm';
import registerComponents from './components/register';
import fillLeadAttribution from './lead-attribution';
import { initTracking } from './tracking';

import.meta.glob('../fonts/*.woff2');

// Pages with Livewire components: Livewire brings its own Alpine. Register
// our components on that instance and start Livewire (which starts Alpine).
// Livewire's automatic script injection is off (config/livewire.php) and
// the layout adds @livewireScriptConfig instead.
registerComponents(Alpine);

Livewire.start();

// Consent-gated Pixel/GA4 (no-op unless configured and accepted) and
// per-visit attribution for lead forms on cached pages.
// In a later task, so the start-up task stays short (milestone 11, TBT).
// Neither is needed for the first paint: tracking waits for consent and
// attribution only matters when a lead form is sent.
setTimeout(() => {
    initTracking();
    fillLeadAttribution();
}, 0);
