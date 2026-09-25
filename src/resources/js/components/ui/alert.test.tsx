import { render, screen } from '@testing-library/react';

import { Alert } from '@/components/ui/alert';

it.each([
    ['info', 'status', 'Notice'],
    ['success', 'status', 'Success'],
    ['warning', 'status', 'Warning'],
    ['danger', 'alert', 'Error'],
] as const)('%s is announced as %s and names its kind without colour', (variant, role, kind) => {
    const { container } = render(<Alert variant={variant}>Message body</Alert>);

    const alert = screen.getByRole(role);
    expect(alert).toHaveTextContent(`${kind}: Message body`);
    // A glyph as well as the label; it is decorative, the kind label carries the meaning.
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
});

it('only genuine errors are assertive', () => {
    render(
        <>
            <Alert variant="info">i</Alert>
            <Alert variant="success">s</Alert>
            <Alert variant="warning">w</Alert>
            <Alert variant="danger">d</Alert>
        </>,
    );

    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(screen.getAllByRole('status')).toHaveLength(3);
});

it('keeps the plain panel a panel: no role, no glyph, block children', () => {
    const { container } = render(
        <Alert>
            <p>No entries match.</p>
        </Alert>,
    );

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(container.querySelector('svg')).not.toBeInTheDocument();
    expect(screen.getByText('No entries match.')).toBeInTheDocument();
});

it('lets the caller override the role and renders an action outside the message', () => {
    render(
        <Alert variant="info" role="alert" action={<button type="button">Dismiss</button>}>
            Heads up
        </Alert>,
    );

    expect(screen.getByRole('alert')).toContainElement(screen.getByRole('button', { name: 'Dismiss' }));
});

it('renders a hostile message as text', () => {
    const hostile = '<img src=x onerror="window.__pwned = true"><script>window.__pwned = true</script>';
    const { container } = render(<Alert variant="danger">{hostile}</Alert>);

    expect(screen.getByRole('alert')).toHaveTextContent(hostile);
    expect(container.querySelector('img, script')).not.toBeInTheDocument();
});
