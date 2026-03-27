/**
 * timer-overlay.js
 *
 * Manages the global active-timer overlay banner.
 * - Fetches active timers on page load via GET /time/timers/active
 * - Renders one tile per timer (clock, context link, editable description, stop button)
 * - Ticks all clocks every second using client-side arithmetic (no polling)
 * - Listens for custom `timerStarted` events to add new tiles without a page reload
 * - Dispatches `timerStopped` events so other JS on the page can react (e.g. refresh lists)
 */

const CSRF = () => document.querySelector('meta[name="csrf-token"]').content;

// Map of entry id → start timestamp (ms) for efficient clock updates
const timerStarts = new Map();

let tickInterval = null;

// ── Colour palette for tile accents ──────────────────────────────────────────
// Cycles through CSS variables defined by the theme. Tiles with no context
// all use the first colour; each unique entry id gets a stable slot.
const PALETTE = [
    'var(--accent)',
    'var(--text-success)',
    'var(--text-info)',
    'var(--text-warning)',
    '#8b5cf6',  // violet
    '#ec4899',  // pink
    '#14b8a6',  // teal
    '#f97316',  // orange
];

const entryColourIndex = new Map();
let nextColourIndex = 0;

function colourFor(entryId) {
    if (!entryColourIndex.has(entryId)) {
        entryColourIndex.set(entryId, nextColourIndex % PALETTE.length);
        nextColourIndex++;
    }
    return PALETTE[entryColourIndex.get(entryId)];
}

// ── Formatting ────────────────────────────────────────────────────────────────

function formatElapsed(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = String(Math.floor(total / 3600)).padStart(2, '0');
    const m = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
    const s = String(total % 60).padStart(2, '0');
    return `${h}:${m}:${s}`;
}

// ── Tick loop ─────────────────────────────────────────────────────────────────

function tick() {
    const now = Date.now();
    timerStarts.forEach((startMs, entryId) => {
        const clockEl = document.querySelector(`[data-timer-id="${entryId}"] .timer-clock`);
        if (clockEl) clockEl.textContent = formatElapsed(now - startMs);
    });
}

function ensureTicking() {
    if (!tickInterval) {
        tickInterval = setInterval(tick, 1000);
        tick(); // immediate first tick
    }
}

function stopTickingIfEmpty() {
    if (timerStarts.size === 0 && tickInterval) {
        clearInterval(tickInterval);
        tickInterval = null;
    }
}

// ── Overlay visibility ────────────────────────────────────────────────────────

function setOverlayVisible(visible) {
    const overlay = document.getElementById('timer-overlay');
    if (!overlay) return;
    overlay.classList.toggle('hidden', !visible);
}

// ── Build a tile element ──────────────────────────────────────────────────────

function buildTile(timer) {
    const { id, started_at, description, context } = timer;
    const colour = colourFor(id);

    const tile = document.createElement('div');
    tile.className = 'timer-tile flex items-center gap-2 rounded-lg border px-3 py-2 shrink-0 text-sm';
    tile.dataset.timerId = id;
    tile.style.cssText = `
        background-color: var(--surface-card);
        border-color: var(--border-success);
        border-left: 3px solid ${colour};
    `;

    // Pulsing dot
    const dot = document.createElement('span');
    dot.className = 'inline-block h-2 w-2 rounded-full animate-pulse shrink-0';
    dot.style.backgroundColor = colour;
    tile.appendChild(dot);

    // Live clock
    const clock = document.createElement('span');
    clock.className = 'timer-clock font-mono font-medium tabular-nums shrink-0';
    clock.style.color = colour;
    clock.textContent = '00:00:00';
    tile.appendChild(clock);

    // Context link (if any)
    if (context) {
        const sep = document.createElement('span');
        sep.className = 'text-xs shrink-0';
        sep.style.color = 'var(--text-muted)';
        sep.textContent = '·';
        tile.appendChild(sep);

        if (context.url) {
            const link = document.createElement('a');
            link.href = context.url;
            link.className = 'text-xs hover:underline shrink-0 max-w-[140px] truncate';
            link.style.color = 'var(--text-secondary)';
            link.title = context.label;
            link.textContent = context.type + ': ' + context.label;
            tile.appendChild(link);
        } else {
            const span = document.createElement('span');
            span.className = 'text-xs shrink-0 max-w-[140px] truncate';
            span.style.color = 'var(--text-secondary)';
            span.title = context.label;
            span.textContent = context.type + ': ' + context.label;
            tile.appendChild(span);
        }
    }

    // Description (click-to-edit)
    const descWrap = document.createElement('span');
    descWrap.className = 'timer-description text-xs cursor-pointer hover:underline shrink-0 max-w-[160px] truncate';
    descWrap.style.color = 'var(--text-primary)';
    descWrap.textContent = description || 'Add description…';
    descWrap.title = description || '';

    descWrap.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.value = description || '';
        input.maxLength = 500;
        input.placeholder = 'Description…';
        input.className = 'text-xs rounded border px-2 py-0.5 outline-none w-40';
        input.style.cssText = `
            background-color: var(--surface-input);
            border-color: var(--border-base);
            color: var(--text-primary);
        `;

        descWrap.replaceWith(input);
        input.focus();
        input.select();

        const commitEdit = () => {
            const newDesc = input.value.trim();
            fetch(`/time/timer/${id}/description`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF(),
                },
                body: JSON.stringify({ description: newDesc || null }),
            });
            descWrap.textContent = newDesc || 'Add description…';
            descWrap.title = newDesc || '';
            input.replaceWith(descWrap);
        };

        input.addEventListener('blur', commitEdit);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); commitEdit(); }
            if (e.key === 'Escape') {
                input.value = description || '';
                input.replaceWith(descWrap);
            }
        });
    });

    tile.appendChild(descWrap);

    // Stop button
    const stopBtn = document.createElement('button');
    stopBtn.type = 'button';
    stopBtn.className = 'text-xs font-medium ml-1 shrink-0 hover:underline';
    stopBtn.style.color = 'var(--text-danger)';
    stopBtn.textContent = 'Stop';
    stopBtn.setAttribute('aria-label', 'Stop timer');

    stopBtn.addEventListener('click', async () => {
        stopBtn.disabled = true;
        stopBtn.textContent = '…';
        try {
            await fetch(`/time/timer/${id}/stop`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF() },
            });
            removeTile(id);
        } catch {
            stopBtn.disabled = false;
            stopBtn.textContent = 'Stop';
        }
    });

    tile.appendChild(stopBtn);

    return tile;
}

// ── Add / remove tiles ────────────────────────────────────────────────────────

function addTile(timer) {
    const container = document.getElementById('timer-tiles');
    if (!container) return;

    // Don't add duplicates
    if (container.querySelector(`[data-timer-id="${timer.id}"]`)) return;

    timerStarts.set(timer.id, new Date(timer.started_at).getTime());
    container.appendChild(buildTile(timer));
    setOverlayVisible(true);
    ensureTicking();
}

function removeTile(entryId) {
    const tile = document.querySelector(`[data-timer-id="${entryId}"]`);
    tile?.remove();
    timerStarts.delete(entryId);

    window.dispatchEvent(new CustomEvent('timerStopped', { detail: { id: entryId } }));

    if (document.querySelectorAll('#timer-tiles [data-timer-id]').length === 0) {
        setOverlayVisible(false);
        stopTickingIfEmpty();
    }
}

// ── Initialise ────────────────────────────────────────────────────────────────

export function init() {
    const overlay = document.getElementById('timer-overlay');
    if (!overlay) return; // not authenticated or no time.log permission

    // Fetch active timers and render tiles
    fetch('/time/timers/active', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then((r) => r.json())
        .then((timers) => {
            timers.forEach(addTile);
        })
        .catch(() => {}); // fail silently — overlay stays hidden

    // Listen for timer started from embedded components or the time screen
    window.addEventListener('timerStarted', (e) => {
        if (e.detail) addTile(e.detail);
    });
}
