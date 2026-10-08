import eslint from '@eslint/js';
import { plugin as shadcn } from '@shadcn/lint';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['resources/js/actions/**', 'resources/js/routes/**', 'resources/js/wayfinder/**'] },
    eslint.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}', 'vite.config.ts', 'playwright.config.ts'],
        languageOptions: {
            globals: globals.browser,
        },
        plugins: {
            'react-hooks': reactHooks,
            'react-refresh': reactRefresh,
        },
        rules: {
            ...reactHooks.configs.recommended.rules,
            'react-refresh/only-export-components': 'off',
        },
    },
    {
        // dnd-kit is confined to its two adapter files (EPIC-011E §9, WP6): everywhere else in
        // the codebase, importing it directly is a lint error, so a domain component cannot
        // quietly pick up a dnd-kit type or hook. `board-dnd.tsx` and `board-card-handle.tsx`
        // are excluded below.
        files: ['resources/js/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            group: ['@dnd-kit/*', '@dnd-kit'],
                            message:
                                'dnd-kit imports are confined to components/projects/board-dnd.tsx and components/projects/board-card-handle.tsx (EPIC-011E §9).',
                        },
                    ],
                },
            ],
        },
    },
    {
        files: [
            'resources/js/components/projects/board-dnd.tsx',
            'resources/js/components/projects/board-card-handle.tsx',
        ],
        rules: {
            'no-restricted-imports': 'off',
        },
    },
    // Direction D design-system rules for React source (`@shadcn/lint`, EPIC-016 follow-up; see AGENTS.md and
    // docs/design/direction-d-design-system.md). The theme and the shared primitives in `components/ui` are discovered
    // from `components.json`. Existing accepted findings are recorded in `eslint-suppressions.json`
    // (ESLint bulk suppressions); anything new fails `npm run lint`.
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/**/*.test.{ts,tsx}', 'resources/js/test/**'],
        plugins: { shadcn },
        rules: {
            'shadcn/no-raw-colors': 'error',
            'shadcn/no-unknown-classes': 'error',
            // Callers add layout; the primitive owns appearance, typography, shape and spacing. Geometry the
            // primitives own (height, size) is denied although Tailwind classes it as layout.
            'shadcn/no-restyle': [
                'error',
                {
                    allow: ['layout'],
                    deny: ['h-*', 'min-h-*', 'max-h-*', 'size-*'],
                    contracts: [
                        {
                            // Bare Radix re-exports in `ui/dropdown-menu.tsx`: they carry no styling of their own,
                            // so their callers style them. `rounded-control` and `duration-motion-*` are Direction D
                            // scales the 0.2.0 class grammar does not know, so they are named.
                            pattern:
                                '^DropdownMenu(Trigger|Group|RadioGroup|RadioItem|ItemIndicator)$',
                            allow: [
                                'layout',
                                'color',
                                'typography',
                                'spacing',
                                'shape',
                                'effects',
                                'motion',
                                'rounded-*',
                                'duration-motion-*',
                            ],
                            deny: [],
                        },
                    ],
                },
            ],
            'shadcn/no-arbitrary-values': ['error', { allow: ['layout'] }],
            'shadcn/require-static-classes': 'error',
            'shadcn/no-inline-styles': 'error',
        },
    },
    {
        // The primitives own their appearance and may need structural values and their own variant calls.
        // `no-raw-colors`, `no-unknown-classes` and `no-inline-styles` stay on here.
        files: ['resources/js/components/ui/**/*.{ts,tsx}'],
        rules: {
            'shadcn/no-restyle': 'off',
            'shadcn/no-arbitrary-values': 'off',
            'shadcn/require-static-classes': 'off',
        },
    },
    {
        // dnd-kit supplies a drag transform, its transition and a touch-action at runtime; those are integration
        // values, not presentation. Allowed only in the two adapter files (EPIC-011E §9).
        files: [
            'resources/js/components/projects/board-dnd.tsx',
            'resources/js/components/projects/board-card-handle.tsx',
        ],
        rules: {
            'shadcn/no-inline-styles': [
                'error',
                { allow: ['transform', 'transition', 'touchAction'] },
            ],
        },
    },
);
