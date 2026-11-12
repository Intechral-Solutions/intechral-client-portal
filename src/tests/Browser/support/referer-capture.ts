import type { BrowserContext } from '@playwright/test';

/**
 * What the browser ACTUALLY sent, request by request, for the credential-protection checks of the invitation page.
 *
 * Asserting a response header proves what the server says, not what the browser does with it: a proxy that appends its own
 * `Referrer-Policy` changes the outcome (a browser applies the LAST of several header values), and that is exactly how the invitation
 * token once reached same-origin `Referer` headers and the web server's access log behind the development nginx. So this records every
 * request the context makes (document, scripts, stylesheets, fonts, images, fetch/XHR, the form's POST, the navigations that follow) with
 * the raw `Referer` the browser put on it, and the specs assert on those.
 *
 * `allHeaders()` is the raw header set (it includes `Referer`, which `headers()` may not). A request that never completes its
 * extra-info (navigated away, aborted) falls back to `headers()` after a short wait and is marked, so a hang is never read as "no leak".
 */
export type SeenRequest = {
    url: string;
    method: string;
    type: string;
    origin: string;
    /** The raw `Referer` request header, or null when the browser sent none. */
    referer: string | null;
    /** False when only the reduced header set was available for this request. */
    rawHeaders: boolean;
};

const RAW_HEADERS_WAIT_MS = 5_000;

export function captureRequests(context: BrowserContext) {
    const pending: Promise<SeenRequest>[] = [];

    context.on('request', (request) => {
        pending.push(
            (async () => {
                let rawHeaders = true;
                const headers = await Promise.race([
                    request.allHeaders(),
                    new Promise<Record<string, string>>((resolve) =>
                        setTimeout(() => {
                            rawHeaders = false;
                            resolve(request.headers());
                        }, RAW_HEADERS_WAIT_MS),
                    ),
                ]);

                return {
                    url: request.url(),
                    method: request.method(),
                    type: request.resourceType(),
                    origin: new URL(request.url()).origin,
                    referer: headers['referer'] ?? null,
                    rawHeaders,
                };
            })(),
        );
    });

    return {
        /** Every request seen so far, with its headers resolved. */
        settled: () => Promise.all(pending),
    };
}

/** The requests whose Referer shows the invitation route, i.e. the token-bearing URL reached something it must not. */
export const leaks = (seen: SeenRequest[]) =>
    seen.filter((request) => request.referer !== null && /\/invitation\//.test(request.referer));

/**
 * A reverse proxy in front of the stack that behaves the way the development nginx used to: it forwards every request unchanged, and on
 * the invitation page it answers with TWO `Referrer-Policy` header lines (the application's `no-referrer`, then a weaker default of its
 * own) and without the document-level meta policy. It exists only for the regression control: a browser applies the LAST header value, so
 * pages served through it leak the token-bearing URL as the same-origin `Referer`, which is exactly what the real checks must be able to
 * see. It listens on loopback in the test process and never touches the application's own configuration.
 *
 * The Host header is rewritten to the proxy's own, so the asset URLs the application writes point back at the proxy and the page's
 * subresources are same-origin requests through it (as they were behind nginx).
 */
export async function startLeakyProxy(
    upstream: string,
): Promise<{ origin: string; close: () => Promise<void> }> {
    const { createServer } = await import('node:http');
    const server = createServer((incoming, outgoing) => {
        void (async () => {
            try {
                const headers = new Headers();

                for (const [name, value] of Object.entries(incoming.headers)) {
                    if (
                        value !== undefined &&
                        !['host', 'connection', 'accept-encoding'].includes(name)
                    ) {
                        headers.set(name, Array.isArray(value) ? value.join(', ') : value);
                    }
                }

                headers.set('host', incoming.headers.host ?? '');
                const body = ['GET', 'HEAD'].includes(incoming.method ?? 'GET')
                    ? undefined
                    : await readBody(incoming);
                const response = await fetch(new URL(incoming.url ?? '/', upstream), {
                    method: incoming.method,
                    headers,
                    body: body === undefined ? undefined : new Uint8Array(body),
                    redirect: 'manual',
                });
                let payload = Buffer.from(await response.arrayBuffer());
                const isInvitationPage = /^\/invitation\//.test(incoming.url ?? '');

                outgoing.statusCode = response.status;

                for (const [name, value] of response.headers) {
                    if (
                        ![
                            'content-encoding',
                            'content-length',
                            'transfer-encoding',
                            'referrer-policy',
                        ].includes(name)
                    ) {
                        outgoing.setHeader(name, value);
                    }
                }

                // The application writes absolute asset URLs from APP_URL, so point them at the proxy as well: the page's own requests
                // must be same-origin requests through it, as they were behind nginx.
                const contentType = response.headers.get('content-type') ?? '';

                if (/text\/html|json|javascript/.test(contentType)) {
                    const own = new URL(upstream).origin;
                    const proxied = `http://${incoming.headers.host ?? ''}`;
                    const escaped = (origin: string) => origin.replace('//', '\\/\\/');

                    payload = Buffer.from(
                        payload
                            .toString('utf8')
                            .split(own)
                            .join(proxied)
                            .split(escaped(own))
                            .join(escaped(proxied)),
                    );
                }

                if (isInvitationPage && contentType.includes('text/html')) {
                    outgoing.setHeader('Referrer-Policy', [
                        'no-referrer',
                        'strict-origin-when-cross-origin',
                    ]);
                    payload = Buffer.from(
                        payload.toString('utf8').replace(/<meta name="referrer"[^>]*>/, ''),
                    );
                } else if (response.headers.has('referrer-policy')) {
                    outgoing.setHeader(
                        'Referrer-Policy',
                        response.headers.get('referrer-policy') ?? '',
                    );
                }

                outgoing.end(payload);
            } catch (error) {
                outgoing.statusCode = 502;
                outgoing.end(`proxy error: ${(error as Error).message}`);
            }
        })();
    });

    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();

    if (address === null || typeof address === 'string') {
        throw new Error('The control proxy did not start.');
    }

    return {
        origin: `http://127.0.0.1:${address.port}`,
        close: () => new Promise<void>((resolve) => server.close(() => resolve())),
    };
}

async function readBody(stream: AsyncIterable<Buffer | string>): Promise<Buffer> {
    const chunks: Buffer[] = [];

    for await (const chunk of stream) {
        chunks.push(Buffer.from(chunk));
    }

    return Buffer.concat(chunks);
}
