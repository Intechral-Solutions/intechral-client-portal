import './bootstrap';

// EPIC-013 WP5: interaction for the Blade Direction D shell (panel, account menu, nav sheet).
import { initBladeShell } from './shell/blade-shell';
// Global timer overlay — runs on every authenticated page
import { init as initTimerOverlay } from './timer-overlay';

document.addEventListener('DOMContentLoaded', initBladeShell);
document.addEventListener('DOMContentLoaded', initTimerOverlay);
