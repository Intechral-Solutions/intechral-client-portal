import { ESLint } from 'eslint';

/**
 * EPIC-017 §15, the lint half of the requester boundary: runs the REAL `eslint.config.js` over probe
 * sources placed at requester paths and asserts the boundary rule fires. A pattern list that only compares
 * the literal specifier would pass `./operator/x`; this fails that, so a future edit to the config cannot
 * quietly drop the protection. `requester-import-guard.test.ts` is the independent static scan.
 *
 * Nothing is written to disk: `lintText` takes the source and the path it pretends to live at.
 */
const RULE = 'requester-boundary/no-operator-import';

const eslint = new ESLint({ cwd: process.cwd() });

async function boundaryErrors(filePath: string, code: string): Promise<string[]> {
    const [result] = await eslint.lintText(code, { filePath });

    return result!.messages
        .filter((message) => message.ruleId === RULE)
        .map((message) => message.message);
}

const IN_HELPDESK = 'resources/js/components/helpdesk/probe.tsx';
const IN_HELPDESK_NESTED = 'resources/js/components/helpdesk/forms/deep/probe.tsx';
const IN_TICKETS = 'resources/js/pages/tickets/probe.tsx';

const forbidden: [string, string, string][] = [
    [
        'relative, same-level folder',
        IN_HELPDESK,
        "import X from './operator/x';\nexport default X;",
    ],
    ['relative, sibling folder', IN_TICKETS, "import X from '../operator/x';\nexport default X;"],
    [
        'relative, up to the page',
        IN_TICKETS,
        "import X from '../../pages/operator/time';\nexport default X;",
    ],
    [
        'relative, from a nested folder',
        IN_HELPDESK_NESTED,
        "import X from '../../operator/x';\nexport default X;",
    ],
    [
        'relative, far up',
        IN_HELPDESK_NESTED,
        "import X from '../../../../pages/operator/time';\nexport default X;",
    ],
    [
        'relative, back through the folder',
        IN_HELPDESK,
        "import X from '../helpdesk/operator/x';\nexport default X;",
    ],
    ['the folder itself', IN_HELPDESK, "import X from './operator';\nexport default X;"],
    ['the alias', IN_TICKETS, "import X from '@/pages/operator/tickets/show';\nexport default X;"],
    [
        'root-absolute',
        IN_TICKETS,
        "import X from '/resources/js/pages/operator/time';\nexport default X;",
    ],
    ['a bare side-effect import', IN_HELPDESK, "import './operator/side-effect';"],
    [
        'an `import type`',
        IN_HELPDESK,
        "import type { T } from './operator/types';\nexport type U = T;",
    ],
    ['a named re-export', IN_HELPDESK, "export { a } from './operator/x';"],
    ['a star re-export', IN_TICKETS, "export * from '@/components/helpdesk/operator';"],
    ['a dynamic import', IN_HELPDESK, "export const load = () => import('./operator/x');"],
    [
        'a dynamic import in a template literal',
        IN_HELPDESK,
        'export const load = () => import(`./operator/x`);',
    ],
    ['require', IN_HELPDESK, "const x = require('./operator/x');\nexport default x;"],
    ['import = require', IN_HELPDESK, "import x = require('./operator/x');\nexport default x;"],
    ['a type-position import()', IN_HELPDESK, "export type T = typeof import('./operator/x');"],
];

const allowed: [string, string, string][] = [
    [
        'a sibling requester module',
        IN_HELPDESK,
        "import X from './conversation-entry';\nexport default X;",
    ],
    [
        'a requester component from a page',
        IN_TICKETS,
        "import X from '../../components/helpdesk/ticket-status';\nexport default X;",
    ],
    [
        'the alias to a requester module',
        IN_TICKETS,
        "import X from '@/components/helpdesk/reply-section';\nexport default X;",
    ],
    [
        'a look-alike page folder',
        IN_TICKETS,
        "import X from '@/pages/operators/x';\nexport default X;",
    ],
    ['a look-alike file name', IN_HELPDESK, "import X from './operator-notes';\nexport default X;"],
    [
        'an unrelated operator folder',
        IN_HELPDESK,
        "import X from '../projects/operator/x';\nexport default X;",
    ],
    [
        'an unrelated operator component',
        IN_HELPDESK,
        "import X from '@/components/operator/x';\nexport default X;",
    ],
    ['a package', IN_HELPDESK, "import X from 'react';\nexport default X;"],
    [
        'the operator module itself',
        'resources/js/components/helpdesk/operator/probe.tsx',
        "import X from '../../../pages/operator/time';\nexport default X;",
    ],
    [
        'a module outside the requester set',
        'resources/js/components/projects/probe.tsx',
        "import X from '@/pages/operator/time';\nexport default X;",
    ],
];

describe('the requester boundary rule, through the real ESLint configuration', () => {
    it.each(forbidden)(
        'refuses %s',
        async (_name, filePath, code) => {
            const errors = await boundaryErrors(filePath, code);

            expect(errors).toHaveLength(1);
            expect(errors[0]).toContain('EPIC-017 §15');
        },
        30_000,
    );

    it.each(allowed)(
        'allows %s',
        async (_name, filePath, code) => {
            expect(await boundaryErrors(filePath, code)).toEqual([]);
        },
        30_000,
    );
});
