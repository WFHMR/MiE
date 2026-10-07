import { createApp } from "vue";
import nsWizard from "./components/ns-wizard.vue";

/**
 * The wizard page previously registered ns-wizard with the avatar component,
 * so the wizard never rendered. It also imported nsButton without using it and
 * left debug logging in the shipped bundle.
 */
const wizardApp = createApp( {} );

wizardApp.component( 'ns-wizard', nsWizard );

wizardApp.mount( '#wizard-wrapper' );
