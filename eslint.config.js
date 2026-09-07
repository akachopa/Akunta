import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    {
        ignores: [
            'ai-worker/**',
            'bootstrap/ssr/**',
            'node_modules/**',
            'public/build/**',
            'vendor/**',
        ],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: {
            react,
            'react-hooks': reactHooks,
        },
        languageOptions: {
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            ...react.configs.flat.recommended.rules,
            ...reactHooks.configs.recommended.rules,

            // Inertia menyediakan JSX runtime otomatis lewat @vitejs/plugin-react.
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',

            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],

            /*
             * Nilai uang dari backend selalu berupa string desimal (plan.md §44.14).
             * `any` akan menghilangkan perbedaan string dan number itu, jadi dilarang.
             */
            '@typescript-eslint/no-explicit-any': 'error',
        },
    },
    prettier,
);
