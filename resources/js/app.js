import Alpine from 'alpinejs';
import siteHeader from './components/site-header';

// Emit fonts and brand images into the Vite manifest so Blade can reference
// them with Vite::asset() (e.g. the display font preload). No JS cost.
import.meta.glob('../fonts/*.woff2');

// Plain Blade pages boot Alpine from here. Pages that render Livewire
// components get Alpine from Livewire's own bundle instead (wired up when
// the first Livewire page lands), so never load both on one page.
//
// Components are registered with Alpine.data() and markup only names them,
// which keeps us compatible with Alpine's CSP build (milestone 10).
Alpine.data('siteHeader', siteHeader);

window.Alpine = Alpine;
Alpine.start();
