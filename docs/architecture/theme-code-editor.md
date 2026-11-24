# Frontend code editor

Website → Code editor is a small CodeMirror 6 screen inside the existing admin. The `Storefront` infrastructure owns access to theme sources; the Admin controller adapts it to the existing authenticated, CSRF-protected Laravel routes. The installation is single-store. Only the registered `default` theme is supported; another configured theme fails closed rather than guessing its filesystem directory.

## Scope and trust

`theme-code.manage` protects the page, listing, read and draft-save operations independently. Ordinary permission synchronization grants nothing to existing non-super roles. Super admins have the existing bypass and can explicitly delegate this trusted-code capability through Roles. Source code is rendered as editor text, never executed or previewed in the admin. Applied frontend code can affect customer sessions and must be reviewed like developer-supplied code; a successful build is not a sandbox or proof of correct business behavior.

Only existing `.css`, `.tsx`, `.jsx`, `.ts`, `.js` files under `resources/js/storefront/themes/Default/` are editable. No Sass compiler is installed, so `.sass` and `.scss` are excluded. Shared storefront contracts, admin, backend, configuration, uploads, dependencies and build artifacts are excluded. Names are bounded ASCII directory/file segments; hidden files, compound dot names (including `.config.ts`, `.test.ts`, `.d.ts`), reserved configuration/build/dependency/test directories, absolute paths, backslashes, traversal, percent-encoded tricks, symlinks (including ancestor directories) and hard-linked files are rejected. Files must be regular UTF-8 text without null bytes, at most 256 KiB. Listing is capped at 500 eligible files. No create, rename, delete or upload endpoint exists.

## Save and conflict handling

Save writes an atomic private JSON draft under `storage/app/private/theme-editor/`, keyed by a SHA-256 of the relative filename. It never writes the source tree, launches a process, evaluates code, or invalidates live assets. Text whitespace is preserved by a route-scoped trim-middleware exemption. Drafts are shared by authorized staff in this installation, not per-browser or per-user workspaces. The response reports whether a draft differs from the deployed source.

An opaque version hash covers the source and saved draft together. Each save revalidates scope, file and current version while holding the shared directory lock. A changed source or another editor's save causes a conflict; there is no force-save flag. If a deployment changes a file with an outstanding draft, the UI preserves the draft but blocks overwriting it: an operator must reconcile it with the deployed source. Copy unsaved work before reopening. Browser-close/navigation guards and the discard dialog protect unsaved edits even if the general admin warning preference is off. A failed save retains the editor text; edits typed during an in-flight save remain dirty after that save succeeds.

`theme_code.saved` events use the existing audit log, recording actor, time and relative file ID, without source, request bodies or full disk paths. Audit failures fail the save and restore the preceding draft. File errors return bounded, actionable messages without local paths or debug traces.

## Explicit apply

Review the saved code as part of deployment before applying it. The build is an operator trust boundary, not a sandbox for hostile code. On the deployment host, with the project's supported Node/pnpm and frozen dependencies installed:

```sh
pnpm theme:apply
```

This is intentionally an **operator command**, not a web-process build service. There is no endpoint accepting commands, build arguments or environment variables. The web screen explains this step and offers View storefront. Do not run a normal deployment or another build concurrently with applying drafts; deploy tools outside this feature do not honor its lock.

The command refuses a Vite `public/hot` marker, takes the same lock as saves, revalidates each draft and its source hash, and copies the existing frontend/build inputs to a private temporary workspace. It does not copy `.env`, backend code, private uploads or server configuration. It uses the installed TypeScript compiler and the existing trusted Vite config, with fixed commands and a three-minute limit per command. No dependencies are installed. There are no extra theme registries or alternative asset pipelines.

A failed type check/build leaves drafts, sources and the published manifest untouched. Successful builds copy hashed assets without replacing existing different files, recheck source versions, update only drafted theme sources and atomically replace `public/build/manifest.json` last. Old hashed assets are retained for open sessions. On a caught publication failure before manifest replacement, changed sources are restored. Saved drafts are removed only after successful publication. A normal refresh then loads the new build; no Laravel layout cache invalidation is needed because only build assets changed. Operator deployments must preserve or commit applied source customizations and private drafts; this is not a version-control replacement. Existing builds and `public/build/assets` must exist before the first apply.

## Operations and limits

- The deployment operator needs write access to theme source files, build outputs and private draft storage. The PHP worker only needs source reads and private draft writes. No Node process is required in PHP. With this repository's Docker tool profile, run `docker compose run --rm node sh -lc 'corepack pnpm theme:apply'` after installing the node tool volume's frozen dependencies as described in development docs.
- A killed process can leave `storage/app/private/theme-editor/lock`. Verify no save/apply process is running before manually removing that empty lock directory. Locks never expire automatically during a long build. Temporary `build-*` folders left by a killed command contain private frontend source and can be removed by the operator once idle.
- Back up theme sources/build artifacts as part of deployment. If valid but faulty React code passes compilation, restore the prior reviewed source/build through deployment. Automated runtime behavior validation and a deployment rollback UI are outside this editor's scope.
- Source-draft conflicts are deliberately operator-resolved. There is no forced overwrite, automatic merge, file history UI, deployment queue, terminal or Git panel.
- English and Greek UI strings are provided; other admin languages use the existing translation fallback. CodeMirror's standard search panel uses its built-in English labels.

## Verification

`ThemeCodeEditorTest` covers approved listing, private saves, whitespace, audit data, permission boundaries, traversal, links, stale edits, source changes, missing/oversized files and storage locking. `CodeEditor.test.tsx` covers dirty states, failed/overlapping saves, in-flight edits, navigation and discard. `node --test scripts/theme-apply.test.mjs` covers staged publication, retained assets, build failures, malicious drafts, concurrent source changes and dev-mode rejection. A real isolated TypeScript/Vite build is also exercised during implementation, without applying a draft to merchant sources.
