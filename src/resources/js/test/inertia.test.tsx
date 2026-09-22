import { Link, router, useForm, usePage } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    setPageProps,
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

    expect(inertiaSpies.form.post).toHaveBeenCalledWith('/things');
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
