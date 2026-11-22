import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { gzipSync } from 'node:zlib';

const options = Object.fromEntries(
    process.argv
        .slice(2)
        .map((argument) => argument.replace(/^--/, '').split('=')),
);

const manifestPath = options.manifest ?? 'public/build/manifest.json';
const fontsPath = options.fonts ?? 'resources/fonts';
const limits = {
    js: Number(options['js-limit'] ?? 150),
    css: Number(options['css-limit'] ?? 30),
    fonts: Number(options['fonts-limit'] ?? 100),
};

const roots = [
    'resources/js/app.tsx',
    'resources/js/pages/welcome.tsx',
    'resources/css/app.css',
];

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const buildDirectory = dirname(manifestPath);

const jsFiles = new Set();
const cssFiles = new Set();
const visited = new Set();

function visit(key) {
    if (visited.has(key)) {
        return;
    }

    visited.add(key);

    const chunk = manifest[key];

    if (chunk === undefined) {
        throw new Error(`Chunk ${key} is missing from ${manifestPath}`);
    }

    if (chunk.file.endsWith('.css')) {
        cssFiles.add(chunk.file);
    } else {
        jsFiles.add(chunk.file);
    }

    (chunk.css ?? []).forEach((file) => cssFiles.add(file));
    (chunk.imports ?? []).forEach(visit);
}

roots.forEach(visit);

const gzipKilobytes = (files) =>
    [...files].reduce(
        (total, file) =>
            total + gzipSync(readFileSync(join(buildDirectory, file))).length,
        0,
    ) / 1024;

const fontKilobytes =
    readdirSync(fontsPath).reduce(
        (total, file) => total + statSync(join(fontsPath, file)).size,
        0,
    ) / 1024;

const rows = [
    ['JS (gzip)', gzipKilobytes(jsFiles), limits.js],
    ['CSS (gzip)', gzipKilobytes(cssFiles), limits.css],
    ['Fuentes', fontKilobytes, limits.fonts],
];

let failed = false;

console.log(
    'Presupuesto'.padEnd(12) +
        'Tamaño'.padStart(12) +
        'Límite'.padStart(10) +
        '  Estado',
);

rows.forEach(([name, size, limit]) => {
    const exceeded = size > limit;
    failed ||= exceeded;

    console.log(
        name.padEnd(12) +
            `${size.toFixed(2)} KB`.padStart(12) +
            `${limit} KB`.padStart(10) +
            `  ${exceeded ? 'SUPERADO' : 'ok'}`,
    );
});

process.exit(failed ? 1 : 0);
