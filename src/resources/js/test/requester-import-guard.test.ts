/**
 * EPIC-017 §15: a requester (customer) Helpdesk module must not import from the operator workspace
 * (`pages/operator/**`, `components/helpdesk/operator/**`).
 *
 * The lint rule (eslint/requester-boundary.js, wired in eslint.config.js) is the enforcement;
 * `requester-import-guard.lint.test.ts` proves the real configuration fires. This test is the independent
 * check, with its own extractor and resolver, so the two cannot fail the same way: it reads the import,
 * export-from, dynamic-import and require references (not `import.meta.glob()`, EPIC-017 §37 D-2) in the
 * requester modules, RESOLVES each against the importing file (a literal pattern misses
 * `./operator/x`), and fails if the target is in the operator workspace. It checks itself too: the module
 * set must be non-empty and must include the pages WP2 ships, and the matcher is proven against sample
 * statements at several depths, so it cannot go quietly blind.
 */

const sources = import.meta.glob(
    [
        '/resources/js/pages/tickets/**/*.{ts,tsx}',
        '/resources/js/components/helpdesk/**/*.{ts,tsx}',
        '!/resources/js/**/*.test.{ts,tsx}',
    ],
    { query: '?raw', import: 'default', eager: true },
) as Record<string, string>;

const ROOT = '/resources/js';
const OPERATOR = [`${ROOT}/pages/operator`, `${ROOT}/components/helpdesk/operator`];

/** Every module reference a file makes: `from '...'` (import, export-from, type forms), bare, dynamic, require. */
const REFERENCES = [
    /\bfrom\s*['"`]([^'"`]+)['"`]/g,
    /(?:^|[;{}\n])\s*import\s*['"`]([^'"`]+)['"`]/g,
    /\bimport\s*\(\s*['"`]([^'"`]+)['"`]\s*\)/g,
    /\brequire\s*\(\s*['"`]([^'"`]+)['"`]\s*\)/g,
];

function specifiers(source: string): string[] {
    return REFERENCES.flatMap((pattern) =>
        Array.from(source.matchAll(pattern), (match) => match[1]!),
    );
}

/** The absolute path a specifier names from `importer`, or null for a package. */
function resolve(importer: string, specifier: string): string | null {
    let parts: string[];

    if (specifier.startsWith('@/')) parts = [ROOT, specifier.slice(2)];
    else if (specifier.startsWith(`${ROOT}/`)) parts = [specifier];
    else if (/^\.\.?(\/|$)/.test(specifier)) parts = [importer.replace(/\/[^/]*$/, ''), specifier];
    else return null;

    const resolved: string[] = [];

    for (const segment of parts.join('/').split('/')) {
        if (segment === '..') resolved.pop();
        else if (segment !== '.' && segment !== '') resolved.push(segment);
    }

    return `/${resolved.join('/')}`;
}

function reachesOperator(importer: string, specifier: string): boolean {
    const target = resolve(importer, specifier);

    return (
        target !== null &&
        OPERATOR.some((operator) => target === operator || target.startsWith(`${operator}/`))
    );
}

const modules = Object.entries(sources).filter(([path]) => !path.includes('/helpdesk/operator/'));

it('scans the requester modules it is meant to guard', () => {
    const paths = modules.map(([path]) => path);

    expect(paths).toEqual(
        expect.arrayContaining([
            '/resources/js/pages/tickets/index.tsx',
            '/resources/js/pages/tickets/create.tsx',
            '/resources/js/pages/tickets/show.tsx',
            '/resources/js/components/helpdesk/reply-section.tsx',
            '/resources/js/components/helpdesk/conversation-entry.tsx',
        ]),
    );
});

it('reads every import spelling the guard has to see', () => {
    const source = `
        import A from './a';
        import type { B } from "../b";
        import { C, type D } from '@/c';
        import './side-effect';
        import
            E
            from './multi-line';
        export { F } from './f';
        export * from './g';
        export type { H } from './h';
        const I = () => import('./i');
        const J = require('./j');
        import K = require('./k');
        export type L = typeof import('./l');
    `;

    expect(new Set(specifiers(source))).toEqual(
        new Set([
            './a',
            '../b',
            '@/c',
            './side-effect',
            './multi-line',
            './f',
            './g',
            './h',
            './i',
            './j',
            './k',
            './l',
        ]),
    );
});

it('resolves a specifier against the importing file, whatever the spelling', () => {
    const cases: [string, string, boolean][] = [
        // The reported bypasses, and their equivalents at other depths.
        ['/resources/js/components/helpdesk/x.tsx', './operator/x', true],
        ['/resources/js/components/helpdesk/x.tsx', './operator', true],
        ['/resources/js/pages/tickets/x.tsx', '../operator/x', true],
        ['/resources/js/pages/tickets/x.tsx', '../operator', true],
        ['/resources/js/pages/tickets/x.tsx', '../../pages/operator/time', true],
        ['/resources/js/components/helpdesk/forms/deep/x.tsx', '../../operator/x', true],
        [
            '/resources/js/components/helpdesk/forms/deep/x.tsx',
            '../../../../pages/operator/x',
            true,
        ],
        ['/resources/js/components/helpdesk/x.tsx', '../helpdesk/operator/x', true],
        ['/resources/js/pages/tickets/x.tsx', '../../components/helpdesk/operator/x', true],
        ['/resources/js/pages/tickets/x.tsx', './../operator/./x', true],
        // The aliased and root-absolute spellings.
        ['/resources/js/pages/tickets/x.tsx', '@/pages/operator/tickets/show', true],
        ['/resources/js/pages/tickets/x.tsx', '@/pages/operator', true],
        ['/resources/js/pages/tickets/x.tsx', '@/components/helpdesk/operator/note', true],
        ['/resources/js/pages/tickets/x.tsx', '/resources/js/pages/operator/time', true],
        // Requester-safe imports, and look-alikes, are untouched.
        ['/resources/js/components/helpdesk/x.tsx', './conversation-entry', false],
        ['/resources/js/pages/tickets/x.tsx', '../../components/helpdesk/ticket-status', false],
        ['/resources/js/pages/tickets/x.tsx', '@/components/helpdesk/reply-section', false],
        ['/resources/js/pages/tickets/x.tsx', '@/pages/operators/x', false],
        ['/resources/js/pages/tickets/x.tsx', '@/pages/tickets/operator/x', false],
        ['/resources/js/components/helpdesk/x.tsx', './operator-notes', false],
        ['/resources/js/components/helpdesk/x.tsx', '../projects/operator/x', false],
        ['/resources/js/components/helpdesk/x.tsx', '@/components/operator/x', false],
        ['/resources/js/components/helpdesk/x.tsx', 'react', false],
        ['/resources/js/components/helpdesk/x.tsx', '@inertiajs/react', false],
    ];

    for (const [importer, specifier, forbidden] of cases) {
        expect(reachesOperator(importer, specifier), `${importer} <- ${specifier}`).toBe(forbidden);
    }
});

it('finds a prohibited import in a whole module, wherever it sits in the file', () => {
    const source = `import { Card } from '@/components/ui/card';\n\nexport const lazy = () => import('../operator/tickets/show');\n`;

    expect(
        specifiers(source).filter((specifier) =>
            reachesOperator('/resources/js/pages/tickets/x.tsx', specifier),
        ),
    ).toEqual(['../operator/tickets/show']);
});

it('imports nothing from the operator workspace in any requester module', () => {
    const offenders = modules.flatMap(([path, source]) =>
        specifiers(source)
            .filter((specifier) => reachesOperator(path, specifier))
            .map((specifier) => `${path} <- ${specifier}`),
    );

    expect(offenders).toEqual([]);
});
