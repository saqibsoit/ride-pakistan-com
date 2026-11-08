import consentBanner from './consent-banner';
import consentSettings from './consent-settings';
import copyButton from './copy-button';
import countdown from './countdown';
import siteHeader from './site-header';
import stepper from './stepper';

// Every Alpine component, registered by name. Markup only names them
// (x-data="stepper"), which keeps us compatible with Alpine's CSP build.
export default function registerComponents(Alpine) {
    Alpine.data('consentBanner', consentBanner);
    Alpine.data('consentSettings', consentSettings);
    Alpine.data('copyButton', copyButton);
    Alpine.data('countdown', countdown);
    Alpine.data('siteHeader', siteHeader);
    Alpine.data('stepper', stepper);
}
