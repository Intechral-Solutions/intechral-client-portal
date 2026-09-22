import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskComments } from '@/components/projects/task-comments';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';
import type { TaskCommentData } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const comments: TaskCommentData[] = [
    {
        id: 1,
        body: 'First',
        createdAt: '2026-06-01T10:00:00.000Z',
        author: { id: 5, name: 'Ada Manager' },
    },
];

it('renders existing comments as literal text, never HTML', () => {
    render(
        <TaskComments
            projectId={7}
            taskId={1}
            canComment
            comments={[
                {
                    id: 2,
                    body: '<script>alert(1)</script>',
                    createdAt: '2026-06-01T10:00:00.000Z',
                    author: { id: 5, name: 'Ada Manager' },
                },
            ]}
        />,
    );

    // Rendered as a text node: no <script> element exists in the DOM.
    expect(document.querySelector('script[src=""]')).not.toBeInTheDocument();
    expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument();
});

it('shows an empty state and the author/timestamp for existing comments', () => {
    const { rerender } = render(<TaskComments projectId={7} taskId={1} canComment comments={[]} />);
    expect(screen.getByText('No comments yet.')).toBeInTheDocument();

    rerender(<TaskComments projectId={7} taskId={1} canComment comments={comments} />);
    expect(screen.getByText('Ada Manager')).toBeInTheDocument();
    expect(screen.getByText('First')).toBeInTheDocument();
});

it('hides the comment form when the viewer may not comment', () => {
    render(<TaskComments projectId={7} taskId={1} canComment={false} comments={comments} />);

    expect(screen.queryByRole('button', { name: /Post comment/ })).not.toBeInTheDocument();
});

it('posts a comment, then resets and refocuses the textarea', async () => {
    const user = userEvent.setup();
    render(<TaskComments projectId={7} taskId={1} canComment comments={comments} />);

    const textarea = screen.getByLabelText('Add a comment');
    await user.type(textarea, 'Hello there');
    await user.click(screen.getByRole('button', { name: 'Post comment' }));

    const submission = submitted()[0]!;
    expect(submission.method).toBe('post');
    expect(submission.url).toBe('/projects/7/tasks/1/comments');
    expect(submission.data).toEqual({ body: 'Hello there' });

    act(() => submission.options.onSuccess?.());
    expect(textarea).toHaveValue('');
    expect(textarea).toHaveFocus();
});

it('shows a server validation error inline', () => {
    setFormErrors({ body: 'The body field is required.' });
    render(<TaskComments projectId={7} taskId={1} canComment comments={comments} />);

    expect(screen.getByText('The body field is required.')).toBeInTheDocument();
});

it('disables the submit button while the body is empty or whitespace', async () => {
    const user = userEvent.setup();
    render(<TaskComments projectId={7} taskId={1} canComment comments={comments} />);

    const button = screen.getByRole('button', { name: 'Post comment' });
    expect(button).toBeDisabled();

    await user.type(screen.getByLabelText('Add a comment'), '   ');
    expect(button).toBeDisabled();
});
