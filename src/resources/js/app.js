import './bootstrap';

// Global timer overlay — runs on every authenticated page
import { init as initTimerOverlay } from './timer-overlay';
document.addEventListener('DOMContentLoaded', initTimerOverlay);
