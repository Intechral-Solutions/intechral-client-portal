import eslint from '@eslint/js';
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
);
