import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { existsSync } from 'node:fs';
import { defineConfig, lazyPlugins } from 'vite-plus';

if (existsSync('.env')) {
    process.loadEnvFile('.env');
}

const vitePort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    ssr: {
        noExternal: true,
    },
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        hmr: {
            host: 'localhost',
            clientPort: vitePort,
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            '.trail/**',
            '.claude/**',
            'database/data/**',
            'docs/**',
            'game-server/**',
            'tools/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            '.claude/**',
            '.trail/**',
            '.mcp.json',
            'CLAUDE.md',
            'boost.json',
            'database/data/**',
            'docs/**',
            'game-server/**',
            'deploy/**',
            'tools/**',
            'tests/Fixtures/**',
            'vendor/**',
            'public/build/**',
            'composer.json',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
