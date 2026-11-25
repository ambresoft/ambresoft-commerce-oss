import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import CategoryActions from './CategoryActions';
import FormErrors from './FormErrors';
import BulkBar from './list/BulkBar';
import { Icon } from './list/IconButton';
import StatusBadge from './list/StatusBadge';
import { useTranslation } from '../../lib/useTranslation';
import type { CategoryBranch, CategorySummary } from '../../types/categories';
import type { SharedProps } from '../../types/page';

// Tree geometry (px): row padding, indent per level and the 32px control box.
const inset = 20;
const indent = 48;
const guide = (level: number) => inset + level * indent + 16;
// Reserve the same action width for parents, leaves and protected rows.
const columns = 'md:grid-cols-[minmax(0,1fr)_11rem_9rem]';
const line = 'pointer-events-none absolute hidden bg-slate-300 md:block';

function Chevron({ open }: { open: boolean }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      fill="none"
      className={`size-4 transition-transform ${open ? 'rotate-90' : ''}`}
    >
      <path d="m8 5 5 5-5 5" stroke="currentColor" strokeWidth="1.6" />
    </svg>
  );
}

export default function CategoryTree({
  initialBranch,
}: {
  initialBranch: CategoryBranch;
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const selection = useForm<{ targets: { id: string; version: number }[] }>({
    targets: [],
  });
  const [rootKey] = useState(initialBranch.parentId ?? 'root');
  const [branches, setBranches] = useState<Record<string, CategoryBranch>>({
    [rootKey]: initialBranch,
  });
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState<string | null>(null);
  const [error, setError] = useState(false);

  const clearSelection = () => {
    selection.reset();
    selection.clearErrors();
  };

  const load = (parentId: string | null, page = 1) => {
    clearSelection();
    const key = parentId ?? 'root';
    setLoading(key);
    setError(false);
    router.get(
      '/admin/categories',
      { parent: parentId ?? '', page },
      {
        only: ['branch'],
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        onSuccess: (response) => {
          const branch = response.props.branch as CategoryBranch;
          setBranches((previous) => ({ ...previous, [key]: branch }));
          if (parentId !== null)
            setExpanded((previous) => new Set(previous).add(parentId));
        },
        onError: () => setError(true),
        onFinish: () => setLoading(null),
      },
    );
  };

  const toggle = (category: CategorySummary, open: boolean) => {
    if (open) {
      clearSelection();
      setExpanded((previous) => {
        const next = new Set(previous);
        next.delete(category.id);
        return next;
      });
    } else if (branches[category.id])
      setExpanded((previous) => new Set(previous).add(category.id));
    else load(category.id);
  };

  const control = (category: CategorySummary, open: boolean) => {
    if (category.childrenCount > 0)
      return (
        <button
          type="button"
          className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50"
          aria-expanded={open}
          aria-label={t(open ? 'categories.collapse' : 'categories.expand', {
            name: category.name,
          })}
          disabled={loading !== null}
          onClick={() => toggle(category, open)}
        >
          {loading === category.id ? (
            <span
              aria-hidden="true"
              className="size-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-primary"
            />
          ) : (
            <Chevron open={open} />
          )}
        </button>
      );
    const selectable =
      capabilities['categories.delete'] &&
      !category.system &&
      category.parentId !== null;
    if (!selectable)
      return <span aria-hidden="true" className="size-8 shrink-0" />;
    const checked = selection.data.targets.some(
      (target) => target.id === category.id,
    );
    return (
      <span className="flex size-8 shrink-0 items-center justify-center">
        <input
          type="checkbox"
          className="size-5"
          aria-label={t('categories.bulk_select', { name: category.name })}
          checked={checked}
          disabled={
            selection.processing ||
            (!checked && selection.data.targets.length >= 40)
          }
          onChange={(event) =>
            selection.setData(
              'targets',
              event.target.checked
                ? [
                    ...selection.data.targets,
                    { id: category.id, version: category.version },
                  ]
                : selection.data.targets.filter(
                    (target) => target.id !== category.id,
                  ),
            )
          }
        />
      </span>
    );
  };

  const renderBranch = (
    key: string,
    depth: number,
    guides: boolean[],
  ): ReactNode => {
    const branch = branches[key];
    if (!branch) return null;
    return (
      <ul>
        {branch.data.map((category, index) => {
          const open = expanded.has(category.id);
          const last = index === branch.data.length - 1;
          const checked = selection.data.targets.some(
            (target) => target.id === category.id,
          );
          return (
            <li key={category.id}>
              <div
                className={`relative grid gap-3 border-b border-slate-100 px-5 py-4 md:items-center ${columns} ${category.system ? 'bg-slate-50' : checked ? 'bg-primary-tint/60' : ''}`}
              >
                {/* Ancestor lines that continue past this row. */}
                {guides.map(
                  (continues, level) =>
                    continues && (
                      <span
                        key={level}
                        aria-hidden="true"
                        className={`${line} inset-y-0 w-px`}
                        style={{ left: guide(level) }}
                      />
                    ),
                )}
                {depth > 0 && (
                  <>
                    <span
                      aria-hidden="true"
                      className="pointer-events-none absolute top-0 hidden h-1/2 rounded-bl-lg border-b border-l border-slate-300 md:block"
                      style={{ left: guide(depth - 1), width: indent - 20 }}
                    />
                    {!last && (
                      <span
                        aria-hidden="true"
                        className={`${line} top-1/2 bottom-0 w-px`}
                        style={{ left: guide(depth - 1) }}
                      />
                    )}
                  </>
                )}
                {open && (
                  <span
                    aria-hidden="true"
                    className={`${line} top-1/2 bottom-0 w-px`}
                    style={{ left: guide(depth) }}
                  />
                )}
                <div
                  className="relative flex min-w-0 items-center gap-3"
                  style={{ paddingLeft: depth * indent }}
                >
                  {control(category, open)}
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-semibold break-words text-slate-900">
                      {category.system && (
                        <Icon name="lock" className="size-4 text-slate-500" />
                      )}
                      {category.name}
                      {category.system && (
                        <span className="rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs font-normal text-slate-500">
                          {t('categories.system')}
                        </span>
                      )}
                    </p>
                    <p className="mt-0.5 text-sm text-slate-500">
                      {t('categories.position')} {category.position} ·{' '}
                      {t(
                        category.childrenCount === 1
                          ? 'categories.children_one'
                          : 'categories.children',
                        { count: category.childrenCount },
                      )}
                    </p>
                    {category.translationState === 'fallback' && (
                      <p className="mt-0.5 text-xs text-amber-700">
                        {t('categories.fallback', {
                          locale: category.resolvedLocale ?? '—',
                        })}
                      </p>
                    )}
                    {category.system && (
                      <p className="mt-1 max-w-lg text-sm text-slate-500">
                        {t('categories.system_hint')}
                      </p>
                    )}
                  </div>
                </div>
                <div>
                  <StatusBadge
                    tone={category.status === 'active' ? 'success' : 'neutral'}
                  >
                    {t('categories.' + category.status)}
                  </StatusBadge>
                </div>
                <CategoryActions category={category} />
              </div>
              {open &&
                renderBranch(
                  category.id,
                  depth + 1,
                  depth === 0 ? [] : [...guides, !last],
                )}
            </li>
          );
        })}
        {!branch.data.length && (
          <li
            className="border-b border-slate-100 py-5 pr-5 text-sm text-slate-500"
            style={{ paddingLeft: inset + depth * indent }}
          >
            {t('common.empty')}
          </li>
        )}
        {branch.lastPage > 1 && (
          <li
            className="flex flex-wrap items-center gap-3 border-b border-slate-100 py-3 pr-5 text-sm"
            style={{ paddingLeft: inset + depth * indent }}
          >
            <button
              className="button-secondary"
              disabled={branch.page <= 1 || loading !== null}
              onClick={() => load(branch.parentId, branch.page - 1)}
            >
              {t('common.previous')}
            </button>
            <span className="text-slate-500">
              {t('common.page', { page: branch.page, last: branch.lastPage })}
            </span>
            <button
              className="button-secondary"
              disabled={branch.page >= branch.lastPage || loading !== null}
              onClick={() => load(branch.parentId, branch.page + 1)}
            >
              {t('common.next')}
            </button>
          </li>
        )}
      </ul>
    );
  };

  return (
    <section
      aria-label={t('categories.tree')}
      aria-busy={loading !== null}
      className="list-card overflow-hidden"
    >
      {selection.data.targets.length > 0 && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            if (
              window.confirm(
                t('categories.bulk_confirm', {
                  count: selection.data.targets.length,
                }),
              )
            ) {
              selection.delete('/admin/categories/bulk', {
                preserveState: 'errors',
              });
            }
          }}
        >
          <BulkBar
            count={selection.data.targets.length}
            note={null}
            onClear={clearSelection}
          >
            <button
              type="submit"
              className="button-secondary gap-2 text-red-700"
              disabled={selection.processing}
            >
              <Icon name="trash" />
              {t(
                selection.processing ? 'common.wait' : 'categories.bulk_delete',
              )}
            </button>
            <p className="basis-full text-xs text-slate-500">
              {t('categories.bulk_selection_hint')}
            </p>
          </BulkBar>
          {Object.keys(selection.errors).length > 0 && (
            <div className="px-5 py-3">
              <FormErrors errors={selection.errors} />
            </div>
          )}
        </form>
      )}
      {error && (
        <p role="alert" className="px-5 pt-4 text-sm text-red-700">
          {t('categories.load_failed')}
        </p>
      )}
      <div
        aria-hidden="true"
        className={`hidden gap-3 border-b border-slate-200 px-5 py-3 text-xs font-medium text-slate-500 md:grid ${columns}`}
      >
        <span>{t('common.name')}</span>
        <span>{t('common.status')}</span>
        <span className="text-right">{t('common.actions')}</span>
      </div>
      {renderBranch(rootKey, 0, [])}
      <p className="px-5 py-4 text-sm text-slate-500">
        {t('categories.tree_hint')}
      </p>
    </section>
  );
}
