import Alpine from 'alpinejs';

// Plain Blade pages boot Alpine from here. Pages that render Livewire
// components get Alpine from Livewire's own bundle instead (wired up when
// the first Livewire page lands), so never load both on one page.
window.Alpine = Alpine;
Alpine.start();
