import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

import { ContextSelector } from '@/components/time/context-selector';

afterEach(() => {
    vi.unstubAllGlobals();
});

it('loads options only after a context type is selected and clears the previous record', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: vi.fn().mockResolvedValue([{ id: 7, label: 'Project Seven' }]),
    });
    vi.stubGlobal('fetch', fetchMock);
    const onChange = vi.fn();
    const user = userEvent.setup();

    const { rerender } = render(
        <ContextSelector idPrefix="test" value={null} onChange={onChange} />,
    );

    expect(fetchMock).not.toHaveBeenCalled();
    await user.selectOptions(screen.getByLabelText('Context'), 'project');
    expect(onChange).toHaveBeenCalledWith(null);
    expect(fetchMock).toHaveBeenCalledWith(
        '/time/context-options?type=project',
        expect.objectContaining({ signal: expect.any(AbortSignal) }),
    );
    expect(await screen.findByRole('option', { name: 'Project Seven' })).toBeInTheDocument();

    rerender(
        <ContextSelector
            idPrefix="test"
            value={{ kind: 'project', id: 7, label: 'Project Seven' }}
            onChange={onChange}
        />,
    );
    await user.selectOptions(screen.getByLabelText('Record'), '7');
    expect(onChange).toHaveBeenLastCalledWith({ kind: 'project', id: 7 });
});

it('reports a failed options request without disabling the type selector', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
    const user = userEvent.setup();

    render(<ContextSelector idPrefix="failure" value={null} onChange={vi.fn()} />);
    await user.selectOptions(screen.getByLabelText('Context'), 'task');

    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent('Context options could not be loaded'),
    );
    expect(screen.getByLabelText('Context')).toBeEnabled();
});
