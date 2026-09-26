/**
 * "Skip to content" — the first focusable element in the shell (Direction D §14.1).
 *
 * Nothing targeted `#main-content` before WP4 (A1.8), so this is genuinely new. It is
 * visually hidden until focused rather than removed, so it stays in Tab order.
 */
export function SkipLink() {
    return (
        <a
            href="#main-content"
            className="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-2 focus-visible:left-2 focus-visible:z-50 focus-visible:rounded-control focus-visible:bg-surface focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-text focus-visible:shadow-overlay focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            Skip to content
        </a>
    );
}
