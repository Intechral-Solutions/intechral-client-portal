/**
 * Whether keyboard focus has been lost: the focused control was removed from the page (so the browser
 * parked focus on `body`), or the shell's navigation policy already moved it to the main landmark
 * because it found `body`. A control the user has since moved to is not lost, so a repair that checks
 * this never steals focus from where the user went.
 */
export function focusIsLost() {
    const active = document.activeElement;

    return (
        !active ||
        active === document.body ||
        active === document.documentElement ||
        !active.isConnected ||
        active.id === 'main-content'
    );
}
