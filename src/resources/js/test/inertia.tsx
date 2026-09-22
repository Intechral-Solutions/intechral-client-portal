/**
 * Shared Inertia test double. Each test file mocks `@inertiajs/react` with the same factory so
 * pages, forms and links behave identically and spies live in one place:
 *
 *     vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
 *     import { inertiaSpies, resetInertiaMock, setFormErrors } from '@/test/inertia';
 *
 * The double never talks to a server. It records calls (`inertiaSpies`) and returns whatever
 * state the test set (`setFormErrors`, `setFormProcessing`, `setPageProps`). Success and failure
 * callbacks are invoked by the test through `inertiaSpies.<method>.mock.calls`.
 *
 * This module must not import `@inertiajs/react` (it is the mock of it).
 */
import { useState } from 'react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { vi } from 'vitest';

import type { SharedPageProps } from '@/types';

const formMethods = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
};

/** Recorded calls. `router.*` are the imperative visits; `form.*` are `useForm()` submissions. */
export const inertiaSpies = {
    router: {
        visit: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
    form: formMethods,
};

const defaultShared: SharedPageProps = {
    app: { name: 'Intechral Portal' },
    auth: { user: null, permissions: [] },
    navigation: [],
    flash: { success: null, error: null, status: null, warning: null },
};

type MockState = {
    props: Record<string, unknown>;
    url: string;
    errors: Record<string, string>;
    processing: boolean;
};

const state: MockState = { props: {}, url: '/', errors: {}, processing: false };

/** Form errors returned by every `useForm()` rendered after this call. */
export function setFormErrors(errors: Record<string, string>) {
    state.errors = errors;
}

export function setFormProcessing(processing: boolean) {
    state.processing = processing;
}

/** Page props (merged over sensible shared props) and URL returned by `usePage()`. */
export function setPageProps(props: Record<string, unknown>, url = '/') {
    state.props = props;
    state.url = url;
}

/** Call from `afterEach`: clears every spy and every piece of configured state. */
export function resetInertiaMock() {
    Object.values(inertiaSpies.router).forEach((spy) => spy.mockReset());
    Object.values(formMethods).forEach((spy) => spy.mockReset());
    state.props = {};
    state.url = '/';
    state.errors = {};
    state.processing = false;
}

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string | { url: string; method?: string };
    // Inertia-only props: accepted so call sites type-check, never forwarded to the DOM.
    prefetch?: unknown;
    preserveScroll?: unknown;
    preserveState?: unknown;
    replace?: unknown;
    only?: unknown;
    method?: unknown;
    data?: unknown;
    as?: unknown;
    children?: ReactNode;
};

const inertiaOnlyLinkProps = [
    'prefetch',
    'preserveScroll',
    'preserveState',
    'replace',
    'only',
    'method',
    'data',
    'as',
];

function Link({ href, ...props }: LinkProps) {
    const anchor = Object.fromEntries(
        Object.entries(props).filter(([key]) => !inertiaOnlyLinkProps.includes(key)),
    );

    return <a href={typeof href === 'string' ? href : href.url} {...anchor} />;
}

type FormSetter<T> = {
    (key: keyof T, value: T[keyof T]): void;
    (values: Partial<T>): void;
    (update: (current: T) => T): void;
};

function useForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setDataState] = useState<T>(initial);

    const setData = ((first: keyof T | Partial<T> | ((current: T) => T), value?: T[keyof T]) => {
        if (typeof first === 'function') setDataState(first);
        else if (typeof first === 'object')
            setDataState((current) => ({ ...current, ...(first as Partial<T>) }));
        else setDataState((current) => ({ ...current, [first]: value }));
    }) as FormSetter<T>;

    return {
        data,
        setData,
        errors: state.errors,
        hasErrors: Object.keys(state.errors).length > 0,
        processing: state.processing,
        ...formMethods,
        // Mirrors Inertia: reset() with no argument restores every initial value.
        reset: (...fields: (keyof T)[]) => {
            formMethods.reset(...fields);
            setDataState((current) => {
                if (!fields.length) return initial;

                const next = { ...current };
                for (const field of fields) next[field] = initial[field];

                return next;
            });
        },
    };
}

function usePage<T extends Record<string, unknown> = SharedPageProps>() {
    return {
        component: 'test',
        url: state.url,
        version: null,
        props: { ...defaultShared, ...state.props } as unknown as T,
    };
}

/** The module factory for `vi.mock('@inertiajs/react', ...)`. */
export function inertiaReactMock() {
    return {
        Head: () => null,
        Link,
        router: inertiaSpies.router,
        useForm,
        usePage,
    };
}
