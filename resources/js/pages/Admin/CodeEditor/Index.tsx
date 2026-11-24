import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import ThemeSourceEditor from '../../../components/admin/ThemeSourceEditor';
import { useTranslation } from '../../../lib/useTranslation';
import { faqRequest } from '../../../lib/faqRequest';
import type { Breadcrumb, NavigationItem } from '../../../types/page';

type FileData = {
  path: string;
  content: string;
  version: string;
  pending: boolean;
  conflict: boolean;
};
export default function CodeEditor({
  files,
  navigation,
  breadcrumbs,
  problem = null,
}: {
  files: string[];
  problem?: string | null;
  navigation: NavigationItem[];
  breadcrumbs: Breadcrumb[];
}) {
  const t = useTranslation();
  const [file, setFile] = useState<FileData | null>(null);
  const [initial, setInitial] = useState('');
  const [content, setContent] = useState('');
  const [generation, setGeneration] = useState(0);
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);
  const [error, setError] = useState(problem ?? '');
  const [notice, setNotice] = useState('');
  const [filter, setFilter] = useState('');
  const searchRef = useRef<(() => void) | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const action = useRef<(() => void) | null>(null);
  const dirty = file !== null && content !== file.content;
  const guarded = (next: () => void) => {
    if (!dirty) {
      next();
      return;
    }
    action.current = next;
    dialog.current?.showModal();
  };
  useEffect(() => {
    if (!dirty && !busy) return;
    const unload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    const off = router.on('before', (event) => {
      event.preventDefault();
      if (busy) return;
      const visit = event.detail.visit;
      action.current = () => {
        off();
        router.visit(visit.url, visit);
      };
      dialog.current?.showModal();
    });
    window.addEventListener('beforeunload', unload);
    return () => {
      off();
      window.removeEventListener('beforeunload', unload);
    };
  }, [dirty, busy]);
  const failure = (cause: unknown) => {
    setError(
      cause instanceof Error &&
        !['request_failed', 'rate_limited'].includes(cause.message)
        ? cause.message
        : t('code_editor.request_failed'),
    );
  };
  const open = async (path: string) => {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const data = await faqRequest<FileData>(
        `/admin/code-editor/file?path=${encodeURIComponent(path)}`,
      );
      setFile(data);
      setContent(data.content);
      setInitial(data.content);
      setGeneration((n) => n + 1);
    } catch (cause) {
      failure(cause);
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };
  const save = async () => {
    if (!file || !dirty || inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    setError('');
    setNotice('');
    const submitted = content;
    try {
      const data = await faqRequest<FileData>('/admin/code-editor/file', {
        path: file.path,
        content: submitted,
        version: file.version,
      });
      setFile(data);
      setNotice(t('code_editor.saved'));
    } catch (cause) {
      failure(cause);
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };
  const discard = () => {
    if (file) {
      setContent(file.content);
      setInitial(file.content);
      setGeneration((n) => n + 1);
      setError('');
    }
  };
  const shown = files.filter((path) =>
    path.toLowerCase().includes(filter.toLowerCase()),
  );
  const groups = new Map<string, string[]>();
  for (const path of shown) {
    const folder = path.includes('/')
      ? path.slice(0, path.lastIndexOf('/'))
      : 'Default';
    groups.set(folder, [...(groups.get(folder) ?? []), path]);
  }
  return (
    <AdminLayout
      title={t('code_editor.title')}
      navigation={navigation}
      breadcrumbs={breadcrumbs}
    >
      <EditorHeader
        heading={t('code_editor.title')}
        iconKey="code"
        status={
          <>
            <span className="text-sm text-slate-500">
              {file?.path ?? t('code_editor.select')}{' '}
              {dirty && (
                <strong className="text-primary">
                  {' '}
                  · {t('code_editor.unsaved')}
                </strong>
              )}
            </span>
          </>
        }
        actions={
          <>
            <button
              className="button-secondary"
              disabled={!file}
              onClick={() => searchRef.current?.()}
            >
              {t('common.search')}
            </button>
            <button
              className="button-secondary"
              disabled={!dirty || busy}
              onClick={() => guarded(discard)}
            >
              {t('code_editor.discard')}
            </button>
            <EditorActionButton
              disabled={!dirty || busy || file?.conflict}
              onClick={() => void save()}
            >
              {busy ? t('common.wait') : t('common.save')}
            </EditorActionButton>
          </>
        }
      />
      <p className="mb-5 max-w-4xl text-sm text-slate-500">
        {t('code_editor.description')}
      </p>
      <details className="mb-5 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <summary className="cursor-pointer font-medium">
          {t('code_editor.apply_title')}
        </summary>
        <p className="mt-3 max-w-4xl text-slate-500">
          {t('code_editor.apply_help')}
        </p>
        <code className="mt-3 inline-block rounded bg-slate-100 px-3 py-2">
          pnpm theme:apply
        </code>
      </details>

      {error && (
        <div
          role="alert"
          className="mb-4 rounded-lg bg-red-50 p-3 text-red-700"
        >
          {error}
        </div>
      )}
      {notice && (
        <p role="status" className="mb-4 text-primary">
          {notice}
        </p>
      )}
      {file?.conflict && (
        <p
          role="alert"
          className="mb-4 rounded-lg bg-amber-50 p-3 text-amber-900"
        >
          {t('code_editor.source_changed')}
        </p>
      )}
      <div className="grid min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white md:grid-cols-[15rem_minmax(0,1fr)]">
        <aside
          className="border-b border-slate-200 p-3 md:border-r md:border-b-0"
          aria-label={t('code_editor.files')}
        >
          <input
            className="field mb-3 w-full"
            aria-label={t('code_editor.filter')}
            placeholder={t('code_editor.filter')}
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
          />
          <div className="max-h-40 overflow-auto md:max-h-[65vh]">
            {[...groups].map(([folder, names]) => (
              <details key={folder} open>
                <summary className="py-2 text-xs font-semibold text-slate-500">
                  {folder}
                </summary>
                {names.map((path) => (
                  <button
                    key={path}
                    disabled={busy}
                    aria-current={file?.path === path ? 'page' : undefined}
                    title={path}
                    className={`block w-full truncate rounded-lg px-3 py-2 text-left font-mono text-xs hover:bg-slate-100 ${file?.path === path ? 'bg-primary-tint text-primary' : ''}`}
                    onClick={() => guarded(() => void open(path))}
                  >
                    {path.split('/').pop()}
                  </button>
                ))}
              </details>
            ))}
            {!shown.length && (
              <p className="p-3 text-sm text-slate-500">
                {t('code_editor.empty')}
              </p>
            )}
          </div>
        </aside>
        <section className="min-w-0">
          {file ? (
            <ThemeSourceEditor
              key={generation}
              path={file.path}
              initial={initial}
              onChange={setContent}
              onSave={() => void save()}
              searchRef={searchRef}
              label={t('code_editor.source')}
            />
          ) : (
            <p className="flex min-h-80 items-center justify-center text-slate-500">
              {t('code_editor.select')}
            </p>
          )}
          <div className="flex flex-wrap justify-between gap-2 border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
            <span id="theme-editor-keyboard">{t('code_editor.shortcuts')}</span>
            <span>
              {file &&
                t(
                  file.pending ? 'code_editor.pending' : 'code_editor.deployed',
                )}
            </span>
          </div>
        </section>
      </div>
      <dialog
        ref={dialog}
        className="m-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-black/50"
        aria-labelledby="discard-title"
      >
        <h2 id="discard-title" className="text-lg font-semibold">
          {t('code_editor.discard_title')}
        </h2>
        <p className="mt-2 text-sm text-slate-500">
          {t('code_editor.discard_help')}
        </p>
        <div className="mt-5 flex justify-end gap-3">
          <button
            className="button-secondary"
            autoFocus
            onClick={() => dialog.current?.close()}
          >
            {t('common.cancel')}
          </button>
          <button
            className="button-primary"
            onClick={() => {
              dialog.current?.close();
              action.current?.();
              action.current = null;
            }}
          >
            {t('code_editor.discard')}
          </button>
        </div>
      </dialog>
    </AdminLayout>
  );
}
