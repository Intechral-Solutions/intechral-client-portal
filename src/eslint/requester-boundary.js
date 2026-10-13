import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * EPIC-017 §15: a requester (customer) Helpdesk module must not import from the operator workspace.
 *
 * `no-restricted-imports` matches the literal specifier, so a relative spelling (`./operator/x` from
 * `components/helpdesk/`, `../operator/x` from `pages/tickets/`) walks straight past any pattern list.
 * This rule instead RESOLVES the specifier against the importing file and compares the target's place
 * under `resources/js`, so every spelling of the same target is the same answer: the `@/` alias, a
 * root-absolute `/resources/js/...`, and any depth of `./` and `../`.
 *
 * It reads these module references: `import`/`import type` (bare side-effect imports included),
 * `export ... from`, `export * from`, `import x = require()`, `require()`, dynamic `import('...')` and
 * `typeof import('...')`. A dynamic import whose target is not a string literal cannot be resolved and is
 * not judged. `import.meta.glob()` is not inspected (optional future hardening, EPIC-017 §37 D-2), and only
 * direct imports are checked, not transitive ones.
 */
const JS_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'resources', 'js');

/** The operator workspace, relative to `resources/js`. A target IS one of these or lives under one. */
export const OPERATOR_MODULES = ['pages/operator', 'components/helpdesk/operator'];

export const MESSAGE =
    'A requester (customer) Helpdesk module must not import from pages/operator/** or components/helpdesk/operator/** (EPIC-017 §15).';

/** Where a specifier points, as an absolute path, or null for a package or anything that is not ours. */
export function resolveSpecifier(importer, specifier) {
    if (specifier.startsWith('@/')) return path.join(JS_ROOT, specifier.slice(2));
    if (specifier.startsWith('/resources/js/')) return path.join(JS_ROOT, specifier.slice('/resources/js/'.length));
    if (specifier === '.' || specifier === '..' || specifier.startsWith('./') || specifier.startsWith('../')) {
        return path.resolve(path.dirname(importer), specifier);
    }

    return null;
}

/** Whether a specifier, written in `importer`, targets the operator workspace. */
export function targetsOperatorWorkspace(importer, specifier) {
    const target = resolveSpecifier(importer, specifier);

    if (target === null) return false;

    const relative = path.relative(JS_ROOT, target).split(path.sep).join('/');

    return OPERATOR_MODULES.some((module) => relative === module || relative.startsWith(`${module}/`));
}

/** The string a module reference names, or null when it is computed. */
function literal(node) {
    if (!node) return null;
    if (node.type === 'Literal' && typeof node.value === 'string') return node.value;
    if (node.type === 'TemplateLiteral' && node.expressions.length === 0) return node.quasis[0]?.value.cooked ?? null;
    if (node.type === 'TSLiteralType') return literal(node.literal);

    return null;
}

export const noOperatorImport = {
    meta: {
        type: 'problem',
        schema: [],
        messages: { operator: MESSAGE },
    },
    create(context) {
        function check(node, source) {
            const specifier = literal(source);

            if (specifier !== null && targetsOperatorWorkspace(context.filename, specifier)) {
                context.report({ node, messageId: 'operator' });
            }
        }

        return {
            ImportDeclaration: (node) => check(node, node.source),
            ExportNamedDeclaration: (node) => node.source && check(node, node.source),
            ExportAllDeclaration: (node) => check(node, node.source),
            ImportExpression: (node) => check(node, node.source),
            TSImportType: (node) => check(node, node.source ?? node.argument ?? node.parameter),
            TSExternalModuleReference: (node) => check(node, node.expression),
            CallExpression: (node) => {
                if (node.callee.type === 'Identifier' && node.callee.name === 'require') {
                    check(node, node.arguments[0]);
                }
            },
        };
    },
};

export const requesterBoundaryPlugin = { rules: { 'no-operator-import': noOperatorImport } };
