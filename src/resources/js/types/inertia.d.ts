import type { SharedPageProps } from './shared';

declare module '@inertiajs/core' {
    // Inertia reads this interface through declaration merging.
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type
    interface PageProps extends SharedPageProps {}
}
