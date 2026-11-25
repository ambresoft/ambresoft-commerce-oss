/** Operator-only: builds private CMS drafts with the installed, trusted Vite config. */
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const THEME_ROOT = 'resources/js/storefront/themes/Default';
const LIMIT = 262144;
const hash = (value) => createHash('sha256').update(value).digest('hex');
const allowed = (name) =>
  name.length <= 180 &&
  /^(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.(css|tsx|jsx|ts|js)$/.test(name) &&
  !/(^|\/)(node_modules|vendor|dist|build|config|tests|__tests__)(\/|\.)/i.test(
    name,
  );

async function regular(root, relative, directory = false) {
  let target = root;
  for (const part of relative.split('/')) {
    target = path.join(target, part);
    const stat = await fs.lstat(target);
    if (stat.isSymbolicLink())
      throw new Error('Symbolic links are not allowed.');
  }
  const stat = await fs.stat(target);
  if (
    directory
      ? !stat.isDirectory()
      : !stat.isFile() || stat.nlink !== 1 || stat.size > LIMIT
  )
    throw new Error('Unavailable or oversized theme file.');
  return target;
}

function run(command, args, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      stdio: 'inherit',
      shell: false,
      env: { ...process.env, NODE_ENV: 'production' },
    });
    const timer = setTimeout(() => {
      child.kill('SIGTERM');
    }, 180000);
    child.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.once('exit', (code) => {
      clearTimeout(timer);
      code === 0
        ? resolve()
        : reject(
            new Error(
              'Build failed. Drafts and the published storefront are unchanged.',
            ),
          );
    });
  });
}

async function atomic(file, bytes) {
  const temporary = `${file}.theme-tmp`;
  await fs.writeFile(temporary, bytes, { flag: 'wx' });
  try {
    await fs.rename(temporary, file);
  } finally {
    await fs.rm(temporary, { force: true });
  }
}

export async function applyTheme(
  root,
  build = async (stage) => {
    await run(
      process.execPath,
      [path.join(root, 'node_modules/typescript/bin/tsc'), '--noEmit'],
      stage,
    );
    await run(
      process.execPath,
      [
        path.join(root, 'node_modules/vite/bin/vite.js'),
        'build',
        '--outDir',
        'public/build',
      ],
      stage,
    );
  },
) {
  const privateDir = 'storage/app/private/theme-editor';
  const directory = await regular(root, privateDir, true);
  const lock = path.join(directory, 'lock');
  await fs.mkdir(lock); // Shared with PHP saves; no unsafe timeout-based lock stealing.
  let stage;
  const restored = [];
  let published = false;
  try {
    try {
      await fs.access(path.join(root, 'public/hot'));
      throw new Error(
        'Stop Vite development mode and remove its stale hot marker before applying drafts.',
      );
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    const names = (await fs.readdir(directory)).filter((name) =>
      /^[a-f0-9]{64}\.json$/.test(name),
    );
    if (!names.length) throw new Error('There are no saved drafts to apply.');
    const drafts = [];
    for (const name of names) {
      const file = path.join(directory, name);
      const stat = await fs.lstat(file);
      if (!stat.isFile() || stat.nlink !== 1 || stat.size > LIMIT * 8)
        throw new Error('Invalid draft storage.');
      const draft = JSON.parse(await fs.readFile(file, 'utf8'));
      if (
        typeof draft.path !== 'string' ||
        !allowed(draft.path) ||
        name !== `${hash(draft.path)}.json` ||
        typeof draft.content !== 'string' ||
        draft.content.includes('\0') ||
        Buffer.byteLength(draft.content) > LIMIT
      )
        throw new Error('Invalid theme draft.');
      const target = await regular(root, `${THEME_ROOT}/${draft.path}`);
      const source = await fs.readFile(target);
      if (
        hash(source) !== draft.base &&
        source.toString('utf8') !== draft.content
      )
        throw new Error(
          `Source changed: ${draft.path}. Resolve this draft against the deployed source before applying.`,
        );
      drafts.push({ ...draft, file, target, source });
    }
    stage = await fs.mkdtemp(path.join(directory, 'build-'));
    // No .env, backend, private uploads or deployment configuration enters the build workspace.
    for (const item of [
      'resources',
      'package.json',
      'tsconfig.json',
      'vite.config.ts',
      'vitest.config.ts',
    ]) {
      await fs.cp(path.join(root, item), path.join(stage, item), {
        recursive: true,
        filter: async (source) => {
          if ((await fs.lstat(source)).isSymbolicLink())
            throw new Error('Build inputs must not contain symlinks.');
          return true;
        },
      });
    }
    await fs.symlink(
      path.join(root, 'node_modules'),
      path.join(stage, 'node_modules'),
      'dir',
    );
    for (const draft of drafts)
      await fs.writeFile(
        path.join(stage, THEME_ROOT, draft.path),
        draft.content,
      );
    await build(stage);
    const output = path.join(stage, 'public/build');
    const manifest = await fs.readFile(path.join(output, 'manifest.json'));
    const entries = JSON.parse(manifest);
    if (!entries['resources/js/app.tsx']?.isEntry)
      throw new Error('Build produced no application entry.');
    const destination = await regular(root, 'public/build', true);
    await regular(root, 'public/build/assets', true);
    // Keep old hashed assets for in-flight sessions; publish the manifest last, atomically.
    const assets = await fs.readdir(path.join(output, 'assets'));
    for (const name of assets) {
      if (!/^[A-Za-z0-9_.-]+$/.test(name))
        throw new Error('Invalid build asset.');
      const bytes = await fs.readFile(path.join(output, 'assets', name));
      const target = path.join(destination, 'assets', name);
      try {
        await fs.writeFile(target, bytes, { flag: 'wx' });
      } catch (error) {
        if (
          error.code !== 'EEXIST' ||
          (await fs.lstat(target)).isSymbolicLink() ||
          !bytes.equals(await fs.readFile(target))
        )
          throw error;
      }
    }
    for (const draft of drafts) {
      await regular(root, `${THEME_ROOT}/${draft.path}`);
      if (!draft.source.equals(await fs.readFile(draft.target)))
        throw new Error(`Source changed during build: ${draft.path}.`);
    }
    for (const draft of drafts) {
      await atomic(draft.target, draft.content);
      restored.push(draft);
    }
    await atomic(path.join(destination, 'manifest.json'), manifest);
    published = true;
    for (const draft of drafts) await fs.unlink(draft.file);
    console.log(
      `Applied ${drafts.length} theme file(s). Reload the storefront to verify.`,
    );
  } catch (error) {
    if (!published)
      for (const draft of restored.reverse())
        await atomic(draft.target, draft.source);
    throw error;
  } finally {
    if (stage) await fs.rm(stage, { recursive: true, force: true });
    await fs.rmdir(lock);
  }
}

if (
  process.argv[1] &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  applyTheme(path.resolve(fileURLToPath(new URL('..', import.meta.url)))).catch(
    (error) => {
      console.error(
        error.code === 'EEXIST'
          ? 'Theme editor is busy. Retry after the current save/build finishes.'
          : error.message,
      );
      process.exitCode = 1;
    },
  );
}
