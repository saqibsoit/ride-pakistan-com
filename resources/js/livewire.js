import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import registerComponents from './components/register';

import.meta.glob('../fonts/*.woff2');

// Pages with Livewire components: Livewire brings its own Alpine. Register
// our components on that instance and start Livewire (which starts Alpine).
// Livewire's automatic script injection is off (config/livewire.php) and
// the layout adds @livewireScriptConfig instead.
registerComponents(Alpine);

Livewire.start();
