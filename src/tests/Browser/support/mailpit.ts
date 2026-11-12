import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Reading the invitation mail the way its recipient would: from Mailpit, the mail catcher the stack's queue worker delivers to.
 *
 * An invitation's plaintext token is stored nowhere: the database holds only its hash, and the token exists in the mailed link and in the
 * encrypted queued job. So the browser journey cannot read it from the application. It reads the message, exactly as a person reading
 * their mailbox would, and follows the link in it. That also proves the whole delivery chain (the route, the after-commit dispatch, the
 * queue worker, the send-time check, the template) rather than assuming it.
 *
 * Needs the stack's `queue` and `mailpit` services (docker-compose.yml starts both with the dev stack; the hosted job starts them
 * explicitly). `E2E_MAILPIT_URL` overrides the address (default `http://mailpit:8025`, the compose service reachable from the app container).
 * When Mailpit cannot be reached the helper FAILS with that reason; it never skips and never invents a link.
 */
export const mailpitURL = (process.env.E2E_MAILPIT_URL ?? 'http://mailpit:8025').replace(/\/$/, '');

export type InvitationMail = {
    id: string;
    /** The path and token of the link, relative so it works against the test's own baseURL whatever APP_URL says. */
    path: string;
    /** The ORIGIN the mailed link is built on (the worker's `APP_URL`). The journey follows `path`; a separate test pins this. */
    origin: string;
    token: string;
    text: string;
    to: string[];
    subject: string;
};

type MailpitSummary = { ID: string };

async function api<T>(path: string, init?: RequestInit): Promise<T> {
    let response: Response;

    try {
        response = await fetch(`${mailpitURL}${path}`, init);
    } catch (error) {
        throw new Error(
            `Mailpit is not reachable at ${mailpitURL} (${(error as Error).message}). The invitation journey reads its mail from there: ` +
                "start the stack's `queue` and `mailpit` services, or set E2E_MAILPIT_URL.",
            { cause: error },
        );
    }

    if (!response.ok) {
        throw new Error(`Mailpit answered ${response.status} for ${path}.`);
    }

    return (await response.json()) as T;
}

/** Every message addressed to `address`, newest first, with its decoded text. */
export async function messagesTo(address: string): Promise<InvitationMail[]> {
    const found = await api<{ messages: MailpitSummary[] | null }>(
        `/api/v1/search?query=${encodeURIComponent(`to:${address}`)}&limit=50`,
    );
    const mails: InvitationMail[] = [];

    for (const summary of found.messages ?? []) {
        const message = await api<{
            Text: string;
            Subject: string;
            To: { Address: string }[];
        }>(`/api/v1/message/${summary.ID}`);
        const link = /(https?:\/\/[^\s/<>"]+)\/invitation\/([A-Za-z0-9]{64})(?![A-Za-z0-9])/.exec(
            message.Text,
        );
        const token = link?.[2];

        if (token) {
            mails.push({
                id: summary.ID,
                path: `/invitation/${token}`,
                origin: new URL(link?.[1] ?? '').origin,
                token,
                text: message.Text,
                to: message.To.map((recipient) => recipient.Address.toLowerCase()),
                subject: message.Subject,
            });
        }
    }

    return mails;
}

/**
 * Wait for `count` invitation messages to `address` (the queue worker sends them a moment after the request). Fails with what it did
 * see if they do not arrive in time: no queue worker, or a send-time refusal that dropped the mail.
 */
export async function waitForInvitationMail(
    address: string,
    { count = 1, timeoutMs = 60_000 }: { count?: number; timeoutMs?: number } = {},
): Promise<InvitationMail[]> {
    const deadline = Date.now() + timeoutMs;
    let seen: InvitationMail[] = [];

    while (Date.now() < deadline) {
        seen = await messagesTo(address);

        if (seen.length >= count) {
            return seen;
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error(
        `Expected ${count} invitation message(s) to ${address} within ${timeoutMs} ms, saw ${seen.length}. ` +
            'Is the queue worker running (docker compose: the `queue` service) and delivering to Mailpit?',
    );
}

/** The one message to `address` (the newest), for a journey that sent exactly one. */
export async function waitForInvitationMailOnce(
    address: string,
    timeoutMs = 60_000,
): Promise<InvitationMail> {
    const [mail] = await waitForInvitationMail(address, { count: 1, timeoutMs });

    if (!mail) {
        throw new Error(`No invitation message to ${address}.`);
    }

    return mail;
}

/** Whether NO invitation message to `address` arrives within `quietMs` (a dropped mail: send-time eligibility). */
export async function stayedQuiet(address: string, quietMs = 5_000): Promise<boolean> {
    await new Promise((resolve) => setTimeout(resolve, quietMs));

    return (await messagesTo(address)).length === 0;
}

/** Best-effort hygiene: remove this run's messages. A failure here never fails a test (Mailpit keeps a bounded mailbox anyway). */
export async function deleteMessagesTo(address: string): Promise<void> {
    try {
        const found = await api<{ messages: MailpitSummary[] | null }>(
            `/api/v1/search?query=${encodeURIComponent(`to:${address}`)}&limit=50`,
        );
        const ids = (found.messages ?? []).map((summary) => summary.ID);

        if (ids.length > 0) {
            await fetch(`${mailpitURL}/api/v1/messages`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ IDs: ids }),
            });
        }
    } catch {
        // Hygiene only.
    }
}

/**
 * The origin the application is CONFIGURED to build absolute links on: `APP_URL` from the environment, else from the stack's `src/.env`
 * (the file the queue worker that sends the mail reads). It is deliberately not the browser's base URL: inside the container Playwright
 * reaches the app by service name while mail links must name the configured public origin. Fails, never skips, when it cannot be read.
 */
export function configuredAppOrigin(): string {
    let value = process.env.APP_URL;

    if (!value) {
        const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

        try {
            value = /^APP_URL=(.*)$/m
                .exec(readFileSync(resolve(appRoot, '.env'), 'utf8'))?.[1]
                ?.trim()
                .replace(/^["']|["']$/g, '');
        } catch {
            // reported below
        }
    }

    if (!value) {
        throw new Error(
            'APP_URL is neither in the environment nor in src/.env: cannot tell which origin the mailed link should use.',
        );
    }

    return new URL(value).origin;
}
