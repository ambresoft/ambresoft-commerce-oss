import { test } from 'node:test';
import assert from 'node:assert/strict';
import { promises as fs } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { applyTheme, THEME_ROOT } from './theme-apply.mjs';
const hash = (v) => createHash('sha256').update(v).digest('hex');
async function fixture(t) {
  const root = await fs.mkdtemp(path.join(tmpdir(), 'theme-apply-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  for (const dir of [
    THEME_ROOT,
    'public/build/assets',
    'storage/app/private/theme-editor',
    'node_modules',
  ])
    await fs.mkdir(path.join(root, dir), { recursive: true });
  for (const file of [
    'package.json',
    'tsconfig.json',
    'vite.config.ts',
    'vitest.config.ts',
  ])
    await fs.writeFile(path.join(root, file), '{}');
  await fs.writeFile(path.join(root, THEME_ROOT, 'theme.css'), 'old{}');
  await fs.writeFile(
    path.join(root, 'public/build/manifest.json'),
    'old manifest',
  );
  await fs.writeFile(
    path.join(root, 'public/build/assets/old.js'),
    'old asset',
  );
  const draft = path.join(
    root,
    'storage/app/private/theme-editor',
    `${hash('theme.css')}.json`,
  );
  await fs.writeFile(
    draft,
    JSON.stringify({
      path: 'theme.css',
      base: hash('old{}'),
      content: 'new{}',
    }),
  );
  return { root, draft };
}
async function build(stage) {
  assert.equal(
    await fs.readFile(path.join(stage, THEME_ROOT, 'theme.css'), 'utf8'),
    'new{}',
  );
  await fs.mkdir(path.join(stage, 'public/build/assets'), { recursive: true });
  await fs.writeFile(
    path.join(stage, 'public/build/assets/new.js'),
    'new asset',
  );
  await fs.writeFile(
    path.join(stage, 'public/build/manifest.json'),
    JSON.stringify({
      'resources/js/app.tsx': { isEntry: true, file: 'assets/new.js' },
    }),
  );
}
test('successful staged build publishes manifest last, updates source, retains old assets', async (t) => {
  const { root, draft } = await fixture(t);
  await applyTheme(root, build);
  assert.equal(
    await fs.readFile(path.join(root, THEME_ROOT, 'theme.css'), 'utf8'),
    'new{}',
  );
  assert.equal(
    await fs.readFile(path.join(root, 'public/build/assets/old.js'), 'utf8'),
    'old asset',
  );
  await assert.rejects(fs.access(draft));
});
test('failed build preserves sources, published manifest and draft, and releases lock', async (t) => {
  const { root, draft } = await fixture(t);
  await assert.rejects(
    applyTheme(root, async () => {
      throw new Error('broken TSX');
    }),
    /broken TSX/,
  );
  assert.equal(
    await fs.readFile(path.join(root, THEME_ROOT, 'theme.css'), 'utf8'),
    'old{}',
  );
  assert.equal(
    await fs.readFile(path.join(root, 'public/build/manifest.json'), 'utf8'),
    'old manifest',
  );
  await fs.access(draft);
  await assert.rejects(
    fs.access(path.join(root, 'storage/app/private/theme-editor/lock')),
  );
});
test('rejects modified source and an existing lock', async (t) => {
  const { root } = await fixture(t);
  await fs.writeFile(path.join(root, THEME_ROOT, 'theme.css'), 'external{}');
  await assert.rejects(applyTheme(root, build), /Source changed/);
  await fs.mkdir(path.join(root, 'storage/app/private/theme-editor/lock'));
  await assert.rejects(applyTheme(root, build), { code: 'EEXIST' });
});
test('rejects malicious draft paths and linked source files', async (t) => {
  const { root, draft } = await fixture(t);
  const original = await fs.readFile(draft);
  await fs.writeFile(
    draft,
    JSON.stringify({ path: '../../backend.php', content: 'evil', base: '' }),
  );
  await assert.rejects(applyTheme(root, build), /Invalid theme draft/);
  await fs.writeFile(draft, original);
  await fs.unlink(path.join(root, THEME_ROOT, 'theme.css'));
  await fs.symlink(
    path.join(root, 'package.json'),
    path.join(root, THEME_ROOT, 'theme.css'),
  );
  await assert.rejects(applyTheme(root, build), /Symbolic links/);
});
test('rejects source edits during the build, leaving active assets intact', async (t) => {
  const { root } = await fixture(t);
  await assert.rejects(
    applyTheme(root, async (stage) => {
      await build(stage);
      await fs.writeFile(
        path.join(root, THEME_ROOT, 'theme.css'),
        'external{}',
      );
    }),
    /Source changed during build/,
  );
  assert.equal(
    await fs.readFile(path.join(root, 'public/build/manifest.json'), 'utf8'),
    'old manifest',
  );
});
test('refuses dev HMR mode and missing application output', async (t) => {
  const { root } = await fixture(t);
  await fs.writeFile(path.join(root, 'public/hot'), 'http://localhost:5173');
  await assert.rejects(applyTheme(root, build), /Stop Vite/);
  await fs.unlink(path.join(root, 'public/hot'));
  await assert.rejects(
    applyTheme(root, async (stage) => {
      await build(stage);
      await fs.writeFile(path.join(stage, 'public/build/manifest.json'), '{}');
    }),
    /no application entry/,
  );
});

test('publication failure restores source files and retains drafts', async (t) => {
  const { root, draft } = await fixture(t);
  await fs.writeFile(
    path.join(root, 'public/build/manifest.json.theme-tmp'),
    'occupied',
  );
  await assert.rejects(applyTheme(root, build), { code: 'EEXIST' });
  assert.equal(
    await fs.readFile(path.join(root, THEME_ROOT, 'theme.css'), 'utf8'),
    'old{}',
  );
  assert.equal(
    await fs.readFile(path.join(root, 'public/build/manifest.json'), 'utf8'),
    'old manifest',
  );
  await fs.access(draft);
});
