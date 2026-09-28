import './bootstrap';

// EPIC-013 WP5: interaction for the Blade Direction D shell (panel, account menu, nav sheet).
import { initBladeShell } from './shell/blade-shell';
// EPIC-013 WP6: the Direction D timer pill and tray in the Blade utility bar.
import { initBladeTimer } from './shell/blade-timer';

document.addEventListener('DOMContentLoaded', initBladeShell);
document.addEventListener('DOMContentLoaded', initBladeTimer);
