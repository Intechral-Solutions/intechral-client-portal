import { Link, router, useForm, usePage } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
    inertiaSpies,
    optimisticSubmitted,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    setPageProps,
    submitted,
} from '@/test/inertia';
import type { SharedPageProps } from '@/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function Probe() {
    const form = useForm({ title: '', tags: ['a'] });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/things');
            }}
        >
            <input
                aria-label="Title"
                value={form.data.title}
                onChange={(event) => form.setData('title', event.target.value)}
            />
            <button type="button" onClick={() => form.setData({ title: 'bulk' })}>
                Bulk
            </button>
            <button
                type="button"
                onClick={() => form.setData((c) => ({ ...c, title: c.title + '!' }))}
            >
                Bang
            </button>
            <button type="button" onClick={() => form.reset()}>
                Reset
            </button>
            <button type="submit" disabled={form.processing}>
                Save
            </button>
            {form.errors.title ? <p role="alert">{form.errors.title}</p> : null}
            <output>{form.hasErrors ? 'has errors' : 'clean'}</output>
        </form>
    );
}

it('supports the three useForm setter shapes and reset', async () => {
    const user = userEvent.setup();
    render(<Probe />);

    await user.type(screen.getByLabelText('Title'), 'x');
    expect(screen.getByLabelText('Title')).toHaveValue('x');

    await user.click(screen.getByRole('button', { name: 'Bulk' }));
    expect(screen.getByLabelText('Title')).toHaveValue('bulk');

    await user.click(screen.getByRole('button', { name: 'Bang' }));
    expect(screen.getByLabelText('Title')).toHaveValue('bulk!');

    await user.click(screen.getByRole('button', { name: 'Reset' }));
    expect(screen.getByLabelText('Title')).toHaveValue('');
    expect(inertiaSpies.form.reset).toHaveBeenCalledTimes(1);
});

it('reflects configured errors and processing state', () => {
    setFormErrors({ title: 'The title field is required.' });
    setFormProcessing(true);
    render(<Probe />);

    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
    expect(screen.getByText('has errors')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
});

it('records a form submission', async () => {
    const user = userEvent.setup();
    render(<Probe />);

    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(inertiaSpies.form.post).toHaveBeenCalledWith('/things', {});
});

it('records each submission with the transformed payload and its options', async () => {
    const user = userEvent.setup();

    function Transforming() {
        const form = useForm({ title: 'a', keys: [{ key: 'k1', id: 1 }] });

        return (
            <button
                type="button"
                onClick={() => {
                    form.transform((data) => ({
                        ...data,
                        keys: data.keys.map(({ id }) => ({ id })),
                    }));
                    form.put('/things/1', { errorBag: 'edit', preserveScroll: true });
                }}
            >
                Send
            </button>
        );
    }

    render(<Transforming />);
    await user.click(screen.getByRole('button', { name: 'Send' }));

    expect(inertiaSpies.form.put).toHaveBeenCalledWith('/things/1', {
        errorBag: 'edit',
        preserveScroll: true,
    });
    expect(submitted()).toEqual([
        {
            method: 'put',
            url: '/things/1',
            options: { errorBag: 'edit', preserveScroll: true },
            data: { title: 'a', keys: [{ id: 1 }] },
        },
    ]);

    resetInertiaMock();
    expect(submitted()).toEqual([]);
});

it('clears errors on request', async () => {
    const user = userEvent.setup();
    setFormErrors({ title: 'Bad.', other: 'Also bad.' });

    function Clearing() {
        const form = useForm({ title: '' });

        return (
            <>
                <button type="button" onClick={() => form.clearErrors('title')}>
                    Clear title
                </button>
                <button type="button" onClick={() => form.clearErrors()}>
                    Clear all
                </button>
                <output>{Object.keys(form.errors).join(',') || 'none'}</output>
            </>
        );
    }

    render(<Clearing />);
    expect(screen.getByRole('status')).toHaveTextContent('title,other');

    await user.click(screen.getByRole('button', { name: 'Clear title' }));
    expect(screen.getByRole('status')).toHaveTextContent('other');

    await user.click(screen.getByRole('button', { name: 'Clear all' }));
    expect(screen.getByRole('status')).toHaveTextContent('none');
});

it('renders links without leaking Inertia-only props to the DOM', () => {
    render(
        <>
            <Link href="/a" prefetch preserveScroll className="x">
                A
            </Link>
            <Link href={{ url: '/b', method: 'get' }}>B</Link>
        </>,
    );

    expect(screen.getByRole('link', { name: 'A' })).toHaveAttribute('href', '/a');
    expect(screen.getByRole('link', { name: 'A' })).not.toHaveAttribute('prefetch');
    expect(screen.getByRole('link', { name: 'A' })).not.toHaveAttribute('preservescroll');
    expect(screen.getByRole('link', { name: 'B' })).toHaveAttribute('href', '/b');
});

it('exposes the router spies and configured page props, and resets between tests', () => {
    setPageProps({ widget: 7 }, '/things?page=2');

    function Page() {
        const page = usePage<SharedPageProps & { widget: number }>();

        return (
            <p>
                {page.props.widget} {page.props.app.name} {page.url}
            </p>
        );
    }

    render(<Page />);
    router.reload({ only: ['columns'] });

    expect(screen.getByText('7 Intechral Portal /things?page=2')).toBeInTheDocument();
    expect(inertiaSpies.router.reload).toHaveBeenCalledWith({ only: ['columns'] });

    resetInertiaMock();
    expect(inertiaSpies.router.reload).not.toHaveBeenCalled();
});

it('records an optimistic visit with its transform, payload and options, and resets between tests', () => {
    const transform = (props: Record<string, unknown>) => ({ columns: props.columns });
    const onSuccess = vi.fn();

    router
        .optimistic(transform)
        .put('/things/1/move', { column_id: 2, position: 0 }, { onSuccess, preserveScroll: true });

    expect(inertiaSpies.router.optimistic).toHaveBeenCalledWith(transform);
    expect(inertiaSpies.router.put).toHaveBeenCalledWith('/things/1/move', {
        onSuccess,
        preserveScroll: true,
    });
    expect(optimisticSubmitted()).toEqual([
        {
            method: 'put',
            url: '/things/1/move',
            data: { column_id: 2, position: 0 },
            options: { onSuccess, preserveScroll: true },
            transform,
        },
    ]);
    // The double never applies the transform itself: a test drives it, exactly as it would
    // drive whatever new props a real optimistic swap produced.
    expect(transform({ columns: ['a', 'b'] })).toEqual({ columns: ['a', 'b'] });

    resetInertiaMock();
    expect(optimisticSubmitted()).toEqual([]);
});
