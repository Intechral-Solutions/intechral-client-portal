/**
 * The one control-height scale (EPIC-013 WP2). Heights are explicit so they never depend on font
 * metrics: WP1d measured a native `<select>` growing 37 -> 38 px when Plex replaced Inter, because
 * its height was content-driven. `Button`, `Input` and `NativeSelect` all read from here, so a
 * button and a field in the same row line up at every size.
 *
 * Touch (`pointer-coarse`) devices get the next step up so targets stay reachable.
 */
export const controlHeight = {
    sm: 'h-8 pointer-coarse:h-10',
    md: 'h-9 pointer-coarse:h-11',
    lg: 'h-10 pointer-coarse:h-12',
} as const;

/**
 * Focus indicator shared by every interactive control: 2px `focus` outline, 2px offset (§14.1).
 * Controls transition colour, background and border only, never `outline-color`: with
 * `transition-colors` the ring would fade in from `currentColor` (black on a light page) over the
 * motion token's 120ms instead of appearing at full strength immediately.
 */
export const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
