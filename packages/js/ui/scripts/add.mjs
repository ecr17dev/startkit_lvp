#!/usr/bin/env node
// Adds components from the shadcn-vue registry (style new-york-v4) to
// @starter/ui, rewriting their imports to the package's relative style.
//
//   pnpm --filter @starter/ui add:component stepper carousel
//   node packages/js/ui/scripts/add.mjs kbd --force
//   node packages/js/ui/scripts/add.mjs kbd --from=/path/to/json   (offline)
//
// Registry dependencies (button, separator, ...) are added too when missing.
// npm dependencies are only reported: add them with pnpm so the lock changes.
import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REGISTRY = 'https://www.shadcn-vue.com/r/styles/new-york-v4';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const components = join(root, 'src/components');

const args = process.argv.slice(2);
const force = args.includes('--force');
const from = args.find((arg) => arg.startsWith('--from='))?.slice(7);
const names = args.filter((arg) => !arg.startsWith('--'));

if (names.length === 0) {
    console.error('Usage: add.mjs <component...> [--force] [--from=dir]');
    process.exit(2);
}

async function item(name) {
    if (from) {
        return JSON.parse(await readFile(join(from, `${name}.json`), 'utf8'));
    }

    const response = await fetch(`${REGISTRY}/${name}.json`);

    if (!response.ok) {
        throw new Error(`${name}: registry answered ${response.status}`);
    }

    return response.json();
}

export function rewrite(source) {
    return source
        .replace(/from (["'])cn\1/g, 'from "../../lib/utils"')
        .replace(
            /from (["'])@\/(?:registry\/new-york-v4\/)?lib\/utils\1/g,
            'from "../../lib/utils"',
        )
        .replace(
            /(["'])@\/(?:registry\/new-york-v4\/)?(?:ui|components\/ui)\/([a-z-]+)\1/g,
            '"../$2"',
        );
}

const pkg = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
const installed = new Set(Object.keys(pkg.dependencies ?? {}));
const missingDependencies = new Set();
const done = new Set();

async function add(name, requested) {
    if (done.has(name)) {
        return;
    }

    done.add(name);

    if (existsSync(join(components, name)) && !(force && requested)) {
        console.log(`= ${name} (already present)`);

        return;
    }

    const data = await item(name);

    for (const dependency of data.registryDependencies ?? []) {
        await add(dependency.replace(/^.*\//, '').replace(/\.json$/, ''), false);
    }

    for (const dependency of data.dependencies ?? []) {
        const base = dependency.replace(/(?<=.)@.*$/, '');

        if (base !== 'cn' && !installed.has(base)) {
            missingDependencies.add(base);
        }
    }

    for (const file of data.files ?? []) {
        const match = file.path.match(/\/ui\/([a-z-]+)\/(.+)$/);

        if (!match) {
            console.warn(`  skipped ${file.path}`);
            continue;
        }

        const target = join(components, match[1], match[2]);
        await mkdir(dirname(target), { recursive: true });
        await writeFile(target, rewrite(file.content));
    }

    console.log(`+ ${name} (${data.files?.length ?? 0} files)`);
}

for (const name of names) {
    await add(name, true);
}

if (missingDependencies.size > 0) {
    console.log(
        `\nMissing npm dependencies: pnpm --filter @starter/ui add ${[...missingDependencies].join(' ')}`,
    );
}
