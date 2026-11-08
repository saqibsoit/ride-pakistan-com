import siteHeader from './site-header';
import stepper from './stepper';

// Every Alpine component, registered by name. Markup only names them
// (x-data="stepper"), which keeps us compatible with Alpine's CSP build.
export default function registerComponents(Alpine) {
    Alpine.data('siteHeader', siteHeader);
    Alpine.data('stepper', stepper);
}
