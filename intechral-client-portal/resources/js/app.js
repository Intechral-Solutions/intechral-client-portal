import './bootstrap';

// Global timer overlay — runs on every authenticated page
import { init as initTimerOverlay } from './timer-overlay';
document.addEventListener('DOMContentLoaded', initTimerOverlay);

// Allocation chart — loaded only on /time/allocation
if (document.getElementById('allocation-chart')) {
    import('./allocation-chart').then((m) => m.init());
}
