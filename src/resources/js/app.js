import './bootstrap';

// EPIC-013 WP5: interaction for the Blade Direction D shell (panel, account menu, nav sheet).
import { initBladeShell } from './shell/blade-shell';
// EPIC-013 WP6: the Direction D timer pill and tray in the Blade utility bar.
import { initBladeTimer } from './shell/blade-timer';

// Release 1.0 (HD-C1): the operator Helpdesk composer's visibility toggle and inline due-date control.
import { initTicketComposer } from './shell/blade-ticket-composer';

document.addEventListener('DOMContentLoaded', initBladeShell);
document.addEventListener('DOMContentLoaded', initBladeTimer);
document.addEventListener('DOMContentLoaded', initTicketComposer);
