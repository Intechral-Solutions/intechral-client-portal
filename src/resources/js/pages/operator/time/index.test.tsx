import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: React.ComponentProps<'a'>) => <a href={href}>{children}</a>,
    router: { get },
}));

import { ReportPage, type TimeReportPageProps } from '@/pages/operator/time';

const props: TimeReportPageProps = {
    entries: {
        data: [
            {
                id: 1,
                date: '2026-09-20',
                userName: 'Ada',
                projectName: 'Portal',
                description: 'Review',
                durationMinutes: 90,
                billable: false,
                billed: false,
                locked: false,
            },
        ],
        current_page: 1,
        last_page: 1,
        from: 1,
        to: 1,
        total: 1,
        links: [],
        prev_page_url: null,
        next_page_url: null,
    },
    byProject: [{ id: 2, name: 'Portal', totalMinutes: 90, billableMinutes: 0 }],
    byUser: [{ id: 3, name: 'Ada', totalMinutes: 90, billableMinutes: 0 }],
    totalMinutes: 90,
    users: [{ id: 3, name: 'Ada' }],
    projects: [{ id: 2, name: 'Portal' }],
    filters: { user_id: null, project_id: null, from: null, to: null, billable: null },
};

afterEach(() => get.mockReset());

it('submits normalized report filters and keeps CSV as a document download', async () => {
    const user = userEvent.setup();
    render(<ReportPage {...props} />);

    await user.selectOptions(screen.getByLabelText('Billing'), '0');
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(get).toHaveBeenCalledWith('/operator/time', expect.objectContaining({ billable: '0' }), {
        preserveState: true,
        replace: true,
    });
    expect(screen.getAllByText('1.5h')).toHaveLength(2);
    expect(screen.getByText('Non-billable')).toBeInTheDocument();
});

it('exports the server-applied filters, not unapplied draft controls', async () => {
    const user = userEvent.setup();
    const applied = {
        user_id: '3',
        project_id: null,
        from: '2026-09-01',
        to: '2026-09-30',
        billable: '1',
    };
    const { rerender } = render(<ReportPage {...props} filters={applied} />);
    const exportLink = () => screen.getByRole('link', { name: 'Export CSV' });
    const appliedHref = '/operator/time/export?user_id=3&from=2026-09-01&to=2026-09-30&billable=1';

    expect(exportLink()).toHaveAttribute('href', appliedHref);

    // Editing drafts must not change what the table shows or what Export downloads.
    await user.selectOptions(screen.getByLabelText('Billing'), '0');
    await user.selectOptions(screen.getByLabelText('User'), '');
    await user.selectOptions(screen.getByLabelText('Project'), '2');
    await user.clear(screen.getByLabelText('From'));
    expect(get).not.toHaveBeenCalled();
    expect(exportLink()).toHaveAttribute('href', appliedHref);

    // Once Apply round-trips through the server, the new applied filters drive Export.
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(get).toHaveBeenCalledWith(
        '/operator/time',
        { project_id: '2', to: '2026-09-30', billable: '0' },
        { preserveState: true, replace: true },
    );
    expect(exportLink()).toHaveAttribute('href', appliedHref);

    rerender(
        <ReportPage
            {...props}
            filters={{
                user_id: null,
                project_id: '2',
                from: null,
                to: '2026-09-30',
                billable: '0',
            }}
        />,
    );
    expect(exportLink()).toHaveAttribute(
        'href',
        '/operator/time/export?project_id=2&to=2026-09-30&billable=0',
    );
});
