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

type FormMethod = 'get' | 'post' | 'put' | 'patch' | 'delete';

export type Submission = {
    method: FormMethod;
    url: string;
    /** The options passed to the submit method: `errorBag`, `preserveScroll`, `onSuccess`, ... */
    options: Record<string, unknown> & {
        onSuccess?: (page?: unknown) => void;
        onError?: (errors: Record<string, string>) => void;
    };
    /** The form data as it would be sent: after any `form.transform()` callback ran. */
    data: Record<string, unknown>;
};

const submissions: Submission[] = [];
const transformers = new WeakMap<
    object,
    (data: Record<string, unknown>) => Record<string, unknown>
>();

/** Every `useForm()` submission in order; the last one is what a test usually asserts on. */
export function submitted(): Submission[] {
    return submissions;
}

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
        optimistic: vi.fn(),
    },
    form: formMethods,
};

export type OptimisticVisitOptions = Record<string, unknown> & {
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onHttpException?: (response: { status: number }) => boolean | void;
    onNetworkError?: (error?: unknown) => boolean | void;
    onFinish?: () => void;
};

export type OptimisticSubmission = {
    method: FormMethod;
    url: string;
    data: Record<string, unknown>;
    options: OptimisticVisitOptions;
    /** The callback passed to `router.optimistic(...)`, so a test can apply it itself. */
    transform: (props: Record<string, unknown>) => Record<string, unknown>;
};

const optimisticSubmissions: OptimisticSubmission[] = [];

/**
 * `router.on('navigate', …)` listeners. Deliberately not a spy: the shell's announcement policy
 * relies on the returned unsubscribe function, which `mockReset()` would strip.
 */
const navigateListeners: Array<(event: unknown) => void> = [];

function on(event: string, callback: (event: unknown) => void) {
    if (event === 'navigate') {
        navigateListeners.push(callback);
    }

    return () => {
        const index = navigateListeners.indexOf(callback);

        if (index >= 0) {
            navigateListeners.splice(index, 1);
        }
    };
}

/** Fire an Inertia navigate, as a real client visit or a back/forward would. */
export function emitInertiaNavigate() {
    for (const listener of [...navigateListeners]) {
        listener({});
    }
}

/**
 * Every `router.optimistic(cb).put/post/patch(...)` call in order. The double does not apply
 * the transform, replay a rollback, or re-render on its own — real Inertia's props update by
 * swapping the whole page component's props, which in these tests happens by the test
 * re-rendering with new props, exactly as `<App>` would. What this double gives a test is the
 * exact callback and options a real optimistic visit would have received, so it can drive them:
 * call `.transform(props)` and re-render with the result for the optimistic phase, then invoke
 * `.options.onSuccess(page)` / `.onError(errors)` / `.onHttpException(response)` /
 * `.onNetworkError()` / `.onFinish()` the way the real request lifecycle would, and re-render
 * with the columns that implies (canonical, or the pre-move baseline for a rollback).
 */
export function optimisticSubmitted(): OptimisticSubmission[] {
    return optimisticSubmissions;
}

function optimistic(transform: (props: Record<string, unknown>) => Record<string, unknown>) {
    inertiaSpies.router.optimistic(transform);

    const submit =
        (method: FormMethod) =>
        (url: string, data: Record<string, unknown> = {}, options: OptimisticVisitOptions = {}) => {
            inertiaSpies.router[method](url, options);
            optimisticSubmissions.push({ method, url, data, options, transform });
        };

    return {
        get: submit('get'),
        post: submit('post'),
        put: submit('put'),
        patch: submit('patch'),
        delete: (url: string, options: OptimisticVisitOptions = {}) =>
            submit('delete')(url, {}, options),
    };
}

const defaultShared: SharedPageProps = {
    app: { name: 'Intechral Portal' },
    auth: { user: null, permissions: [] },
    shell: { presentation: 'operational' },
    navigation: { currentWorkspace: null, workspaces: [] },
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
    submissions.length = 0;
    optimisticSubmissions.length = 0;
    navigateListeners.length = 0;
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

    // `data-inertia-link` is how a test tells a client visit from a document navigation: real
    // Inertia intercepts the click, a plain `<a>` does not, and that distinction is a contract the
    // shell must preserve per destination (EPIC-013 §11.4).
    return (
        <a href={typeof href === 'string' ? href : href.url} data-inertia-link="true" {...anchor} />
    );
}

type FormSetter<T> = {
    (key: keyof T, value: T[keyof T]): void;
    (values: Partial<T>): void;
    (update: (current: T) => T): void;
};

function useForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setDataState] = useState<T>(initial);
    const [errors, setErrors] = useState<Record<string, string>>(state.errors);
    // Keyed by a stable per-hook object: a callback set in one event handler must be visible to
    // the submit call in the same handler, before any re-render, so it cannot live in state.
    const [holder] = useState(() => ({}));

    const setData = ((first: keyof T | Partial<T> | ((current: T) => T), value?: T[keyof T]) => {
        if (typeof first === 'function') setDataState(first);
        else if (typeof first === 'object')
            setDataState((current) => ({ ...current, ...(first as Partial<T>) }));
        else setDataState((current) => ({ ...current, [first]: value }));
    }) as FormSetter<T>;

    // Mirrors Inertia: the method spies see (url, options); the data that would be sent (after
    // transform) is recorded separately so tests can assert the exact payload.
    const submit =
        (method: FormMethod) =>
        (url: string, options: Submission['options'] = {}) => {
            formMethods[method](url, options);
            submissions.push({
                method,
                url,
                options,
                data: transformers.get(holder)?.(data) ?? { ...data },
            });
        };

    return {
        data,
        setData,
        errors,
        hasErrors: Object.keys(errors).length > 0,
        processing: state.processing,
        transform: (callback: (data: T) => Record<string, unknown>) => {
            transformers.set(
                holder,
                callback as (data: Record<string, unknown>) => Record<string, unknown>,
            );
        },
        get: submit('get'),
        post: submit('post'),
        put: submit('put'),
        patch: submit('patch'),
        delete: submit('delete'),
        clearErrors: (...fields: string[]) => {
            formMethods.clearErrors(...fields);
            setErrors((current) =>
                fields.length
                    ? Object.fromEntries(
                          Object.entries(current).filter(([key]) => !fields.includes(key)),
                      )
                    : {},
            );
        },
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
        router: { ...inertiaSpies.router, optimistic, on },
        useForm,
        usePage,
    };
}
