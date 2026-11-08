import Alpine from 'alpinejs';
import registerComponents from './components/register';

// Emit fonts and brand images into the Vite manifest so Blade can reference
// them with Vite::asset() (e.g. the display font preload). No JS cost.
import.meta.glob('../fonts/*.woff2');

// Plain Blade pages boot Alpine from here. Pages that render Livewire
// components load resources/js/livewire.js instead, which uses the Alpine
// bundled with Livewire, so Alpine never loads twice on one page.
registerComponents(Alpine);

window.Alpine = Alpine;
Alpine.start();
