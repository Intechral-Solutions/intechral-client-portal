import { isValidElement } from 'react';

/**
 * The page props a layout function was handed, when it was handed the page.
 *
 * Inertia 3 calls a `Page.layout` function **twice**: first with the raw props object, only to learn what
 * the function returns, then with the page element it should wrap. A layout that reads its page's own
 * props (the breadcrumb trail of a record page) must therefore read them from the element and tolerate
 * the probe: `undefined` here means "not the page", and the layout renders without them. Reading
 * `page.props` directly crashed the page with a blank screen.
 */
export function layoutPageProps<T>(page: unknown): T | undefined {
    return isValidElement<T>(page) ? page.props : undefined;
}
