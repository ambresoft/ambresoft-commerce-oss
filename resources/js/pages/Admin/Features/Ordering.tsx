import EditorHeader from '../../../components/admin/EditorHeader';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm } from '@inertiajs/react';
import {
  Fragment,
  useEffect,
  useMemo,
  useState,
  type DragEvent,
  type ReactNode,
} from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Dropdown, { Chevron } from '../../../components/admin/Dropdown';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { Feature, FeatureGroup } from '../../../types/features';

type Layout = { id: string; features: string[] }[];
type Drag = { kind: 'feature' | 'group'; id: string };
type Target = { group: string; index: number };
type Entry = {
  before: Layout;
  kind: 'feature' | 'group';
  name: string;
  groupName: string;
  position: number;
  crossGroup: boolean;
  other?: { name: string; relation: 'above' | 'below' };
};

const clone = (layout: Layout): Layout =>
  layout.map((g) => ({ id: g.id, features: [...g.features] }));
const same = (a: Layout, b: Layout) => JSON.stringify(a) === JSON.stringify(b);

/** Moves a feature; `index` is its final index inside the target group. */
function moveFeature(
  layout: Layout,
  id: string,
  group: string,
  index: number,
): Layout {
  const next = layout.map((g) => ({
    id: g.id,
    features: g.features.filter((f) => f !== id),
  }));
  const target = next.find((g) => g.id === group);
  if (!target) return layout;
  target.features.splice(
    Math.max(0, Math.min(index, target.features.length)),
    0,
    id,
  );
  return next;
}

function moveGroup(layout: Layout, id: string, index: number): Layout {
  const group = layout.find((g) => g.id === id);
  if (!group) return layout;
  const next = layout.filter((g) => g.id !== id);
  next.splice(Math.max(0, Math.min(index, next.length)), 0, group);
  return clone(next);
}

function Grip({ className = '' }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      className={`size-4 shrink-0 ${className}`}
      fill="currentColor"
    >
      {[6, 14].map((x) =>
        [4, 10, 16].map((y) => (
          <circle key={x + ':' + y} cx={x} cy={y} r="1.4" />
        )),
      )}
    </svg>
  );
}

function Arrow({ down = false }: { down?: boolean }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      className="size-4"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d={down ? 'M10 4v12m-5-5 5 5 5-5' : 'M10 16V4m-5 5 5-5 5 5'} />
    </svg>
  );
}

function ArrowButton({
  down,
  label,
  disabled,
  onClick,
}: {
  down?: boolean;
  label: string;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="inline-flex size-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-white"
    >
      <Arrow down={down} />
    </button>
  );
}

export default function Ordering(props: Props) {
  return <Editor key={props.catalogVersion} {...props} />;
}

type Props = AdminShell & {
  groups: FeatureGroup[];
  features: Feature[];
  catalogVersion: number;
};

function Editor({ groups, features, catalogVersion, ...shell }: Props) {
  const t = useTranslation();
  const original = useMemo<Layout>(
    () =>
      groups.map((g) => ({
        id: g.id,
        features: features.filter((f) => f.group_id === g.id).map((f) => f.id),
      })),
    [groups, features],
  );
  const [layout, setLayout] = useState<Layout>(() => clone(original));
  const [entries, setEntries] = useState<Entry[]>([]);
  const [drag, setDrag] = useState<Drag | null>(null);
  const [over, setOver] = useState<Target | null>(null);
  const [groupOver, setGroupOver] = useState<number | null>(null);
  const form = useForm({ catalog_version: catalogVersion, groups: original });
  useDirtyForm(form);

  const byId = useMemo(
    () => new Map(features.map((f) => [f.id, f])),
    [features],
  );
  const groupName = (id: string) => groups.find((g) => g.id === id)?.name ?? '';
  const featureName = (id: string) => {
    const f = byId.get(id);
    return f ? f.name || f.code : '';
  };
  const dirty = !same(layout, original);
  const home = useMemo(() => {
    const places = new Map<string, string>();
    original.forEach((g) =>
      g.features.forEach((id, i) => places.set(id, `${g.id}:${i}`)),
    );
    return places;
  }, [original]);
  const changed = useMemo(() => {
    const ids = new Set<string>();
    layout.forEach((g) =>
      g.features.forEach((id, i) => {
        if (home.get(id) !== `${g.id}:${i}`) ids.add(id);
      }),
    );
    return ids;
  }, [layout, home]);

  useEffect(() => {
    if (!dirty) return;
    const unload = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', unload);
    return () => window.removeEventListener('beforeunload', unload);
  }, [dirty]);

  const commit = (next: Layout, entry: Omit<Entry, 'before'>) => {
    if (same(next, layout)) return;
    setEntries((list) => [...list, { ...entry, before: layout }]);
    setLayout(next);
  };

  const placeFeature = (id: string, group: string, index: number) => {
    const f = byId.get(id);
    const from = layout.find((g) => g.features.includes(id));
    if (!f || !from) return;
    // Archived features keep their group; they can only be reordered inside it.
    if (f.status === 'archived' && from.id !== group) return;
    const next = moveFeature(layout, id, group, index);
    const list = next.find((g) => g.id === group)!.features;
    const at = list.indexOf(id);
    const neighbour =
      list.length < 2
        ? undefined
        : at === 0
          ? { id: list[1], relation: 'above' as const }
          : { id: list[at - 1], relation: 'below' as const };
    commit(next, {
      kind: 'feature',
      name: featureName(id),
      groupName: groupName(group),
      position: at + 1,
      crossGroup: from.id !== group,
      other:
        from.id === group && neighbour
          ? { name: featureName(neighbour.id), relation: neighbour.relation }
          : undefined,
    });
  };

  const nudgeFeature = (id: string, by: number) => {
    const group = layout.find((g) => g.features.includes(id));
    if (!group) return;
    const to = group.features.indexOf(id) + by;
    if (to < 0 || to >= group.features.length) return;
    placeFeature(id, group.id, to);
  };

  const placeGroup = (id: string, index: number) => {
    const next = moveGroup(layout, id, index);
    commit(next, {
      kind: 'group',
      name: groupName(id),
      groupName: groupName(id),
      position: next.findIndex((g) => g.id === id) + 1,
      crossGroup: false,
    });
  };

  const nudgeGroup = (id: string, by: number) => {
    const at = layout.findIndex((g) => g.id === id);
    if (at + by < 0 || at + by >= layout.length) return;
    placeGroup(id, at + by);
  };

  const clearDrag = () => {
    setDrag(null);
    setOver(null);
    setGroupOver(null);
  };

  const startDrag = (event: DragEvent, item: Drag, label: string) => {
    event.stopPropagation();
    const transfer = event.dataTransfer;
    if (transfer) {
      transfer.effectAllowed = 'move';
      transfer.setData('text/plain', item.id);
      if (transfer.setDragImage) {
        const pill = document.createElement('div');
        pill.textContent = label;
        pill.className =
          'fixed -top-96 left-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-lg';
        document.body.appendChild(pill);
        transfer.setDragImage(pill, 16, 16);
        setTimeout(() => pill.remove(), 0);
      }
    }
    setDrag(item);
  };

  const dragged = drag?.kind === 'feature' ? byId.get(drag.id) : undefined;
  const draggedFrom =
    drag?.kind === 'feature'
      ? layout.find((g) => g.features.includes(drag.id))?.id
      : undefined;
  const accepts = (group: string) =>
    drag?.kind === 'feature' &&
    !(dragged?.status === 'archived' && draggedFrom !== group);

  const dropFeature = (event: DragEvent, group: string, index: number) => {
    event.preventDefault();
    event.stopPropagation();
    if (drag?.kind === 'feature' && accepts(group)) {
      const list = layout.find((g) => g.id === group)!.features;
      const from = list.indexOf(drag.id);
      placeFeature(
        drag.id,
        group,
        from >= 0 && from < index ? index - 1 : index,
      );
    }
    clearDrag();
  };

  const after = (event: DragEvent) => {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    return event.clientY > rect.top + rect.height / 2;
  };

  const submit = () => {
    form.transform(() => ({ catalog_version: catalogVersion, groups: layout }));
    form.post('/admin/features/ordering', { preserveScroll: true });
  };

  const entryLine = (entry: Entry): ReactNode =>
    entry.kind === 'group' ? (
      <strong className="font-semibold">{entry.name}</strong>
    ) : entry.other ? (
      <>
        <strong className="font-semibold">{entry.name}</strong>{' '}
        {entry.other.relation === 'above'
          ? t('features.ordering_above')
          : t('features.ordering_below')}{' '}
        <strong className="font-semibold">{entry.other.name}</strong>
      </>
    ) : (
      <>
        <strong className="font-semibold">{entry.name}</strong> →{' '}
        {entry.groupName}
      </>
    );
  const entryDetail = (entry: Entry) =>
    entry.kind === 'group'
      ? t('features.ordering_group_moved', { position: entry.position })
      : t('features.ordering_position', {
          group: entry.groupName,
          position: entry.position,
        });

  const keyboard = t('features.ordering_keyboard').split(/(:up|:down)/);
  const moving =
    drag &&
    (drag.kind === 'group'
      ? { name: groupName(drag.id), target: null }
      : {
          name: featureName(drag.id),
          target:
            over && over.group !== draggedFrom ? groupName(over.group) : null,
        });

  return (
    <AdminLayout title={t('features.ordering')} {...shell}>
      <EditorHeader
        heading={t('features.ordering')}
        iconKey="features"
        backHref="/admin/features"
        backLabel={t('features.title')}
      />
      <p className="mb-6 max-w-3xl text-sm text-slate-600">
        {t('features.ordering_help')}
      </p>
      <form
        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
      >
        <div className="space-y-4">
          <FormErrors errors={form.errors} />
          {layout.map((g, gi) => {
            const targeted =
              drag?.kind === 'feature' && over?.group === g.id && accepts(g.id);
            return (
              <Fragment key={g.id}>
                {groupOver === gi && drag?.kind === 'group' && (
                  <div className="h-0.5 rounded bg-emerald-500" />
                )}
                <section
                  aria-label={groupName(g.id)}
                  className={`rounded-xl border bg-white transition-colors ${
                    targeted
                      ? 'border-emerald-500 ring-2 ring-emerald-100'
                      : 'border-slate-200'
                  } ${drag?.kind === 'group' && drag.id === g.id ? 'opacity-60' : ''}`}
                  onDragOver={(e) => {
                    if (drag?.kind !== 'group' || drag.id === g.id) return;
                    e.preventDefault();
                    setGroupOver(after(e) ? gi + 1 : gi);
                  }}
                  onDrop={(e) => {
                    if (drag?.kind !== 'group') return;
                    e.preventDefault();
                    const at = layout.findIndex((x) => x.id === drag.id);
                    const to = groupOver ?? gi;
                    placeGroup(drag.id, at < to ? to - 1 : to);
                    clearDrag();
                  }}
                >
                  <header
                    className="flex items-center gap-3 border-b border-slate-100 px-4 py-3"
                    draggable
                    onDragStart={(e) =>
                      startDrag(e, { kind: 'group', id: g.id }, groupName(g.id))
                    }
                    onDragEnd={clearDrag}
                  >
                    <button
                      type="button"
                      aria-label={t('features.drag_handle', {
                        name: groupName(g.id),
                      })}
                      className="cursor-grab rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                      onKeyDown={(e) => {
                        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                          e.preventDefault();
                          nudgeGroup(g.id, e.key === 'ArrowUp' ? -1 : 1);
                        }
                      }}
                    >
                      <Grip />
                    </button>
                    <h2 className="font-semibold text-slate-900">
                      {groupName(g.id)}
                    </h2>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 tabular-nums">
                      {g.features.length}
                    </span>
                    <div className="ml-auto flex gap-2">
                      <ArrowButton
                        label={t('features.up')}
                        disabled={gi === 0}
                        onClick={() => nudgeGroup(g.id, -1)}
                      />
                      <ArrowButton
                        down
                        label={t('features.down')}
                        disabled={gi === layout.length - 1}
                        onClick={() => nudgeGroup(g.id, 1)}
                      />
                    </div>
                  </header>
                  <ol className="space-y-1 p-2">
                    {g.features.map((id, i) => {
                      const f = byId.get(id)!;
                      const archived = f.status === 'archived';
                      const lifted = drag?.kind === 'feature' && drag.id === id;
                      return (
                        <Fragment key={id}>
                          {targeted && over?.index === i && !lifted && (
                            <li
                              aria-hidden="true"
                              className="h-0.5 rounded bg-emerald-500"
                            />
                          )}
                          <li
                            draggable
                            data-feature={id}
                            onDragStart={(e) =>
                              startDrag(
                                e,
                                { kind: 'feature', id },
                                featureName(id),
                              )
                            }
                            onDragEnd={clearDrag}
                            onDragOver={(e) => {
                              if (drag?.kind !== 'feature' || !accepts(g.id))
                                return;
                              e.preventDefault();
                              e.stopPropagation();
                              setOver({
                                group: g.id,
                                index: after(e) ? i + 1 : i,
                              });
                            }}
                            onDrop={(e) =>
                              dropFeature(e, g.id, over?.index ?? i)
                            }
                            className={`group/row flex items-center gap-3 rounded-lg border px-2 py-2 ${
                              lifted
                                ? 'border-dashed border-slate-300 bg-slate-50 text-slate-400'
                                : 'border-transparent hover:bg-slate-50'
                            }`}
                          >
                            <button
                              type="button"
                              aria-label={t('features.drag_handle', {
                                name: featureName(id),
                              })}
                              className="cursor-grab rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                              onKeyDown={(e) => {
                                if (
                                  e.key === 'ArrowUp' ||
                                  e.key === 'ArrowDown'
                                ) {
                                  e.preventDefault();
                                  nudgeFeature(
                                    id,
                                    e.key === 'ArrowUp' ? -1 : 1,
                                  );
                                }
                              }}
                            >
                              <Grip />
                            </button>
                            <span className="w-5 text-sm text-slate-500 tabular-nums">
                              {i + 1}
                            </span>
                            {changed.has(id) && (
                              <span
                                role="img"
                                aria-label={t('features.ordering_changed')}
                                className="size-1.5 shrink-0 rounded-full bg-emerald-600"
                              />
                            )}
                            <span className="min-w-0 truncate text-sm font-medium text-slate-800">
                              {f.name || f.code}
                              {archived && (
                                <span className="ml-2 text-xs font-normal text-slate-500">
                                  {t('features.archived')}
                                </span>
                              )}
                            </span>
                            <div className="ml-auto flex items-center gap-2 opacity-0 transition-opacity group-focus-within/row:opacity-100 group-hover/row:opacity-100 has-[[aria-expanded='true']]:opacity-100 [@media(hover:none)]:opacity-100">
                              {!archived && layout.length > 1 && (
                                <Dropdown
                                  label={`${t('features.move_to_group')}: ${featureName(id)}`}
                                  trigger={
                                    <>
                                      {t('features.move_to_group')}
                                      <Chevron />
                                    </>
                                  }
                                  triggerClassName="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                  panelClassName="w-56 p-1.5"
                                >
                                  {(close) => (
                                    <div role="group">
                                      <p className="px-2.5 pt-1 pb-1.5 text-xs text-slate-500">
                                        {t('features.move_to_group')}
                                      </p>
                                      {layout.map((target) => (
                                        <button
                                          key={target.id}
                                          type="button"
                                          aria-current={target.id === g.id}
                                          className="flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left text-sm text-slate-800 hover:bg-slate-50 aria-[current=true]:text-slate-400"
                                          onClick={() => {
                                            close();
                                            if (target.id !== g.id)
                                              placeFeature(
                                                id,
                                                target.id,
                                                target.features.length,
                                              );
                                          }}
                                        >
                                          {groupName(target.id)}
                                          {target.id === g.id && (
                                            <span aria-hidden="true">✓</span>
                                          )}
                                        </button>
                                      ))}
                                    </div>
                                  )}
                                </Dropdown>
                              )}
                              <ArrowButton
                                label={`${t('features.up')}: ${featureName(id)}`}
                                disabled={i === 0}
                                onClick={() => nudgeFeature(id, -1)}
                              />
                              <ArrowButton
                                down
                                label={`${t('features.down')}: ${featureName(id)}`}
                                disabled={i === g.features.length - 1}
                                onClick={() => nudgeFeature(id, 1)}
                              />
                            </div>
                          </li>
                        </Fragment>
                      );
                    })}
                    {drag?.kind === 'feature' && accepts(g.id) && (
                      <li
                        onDragOver={(e) => {
                          e.preventDefault();
                          e.stopPropagation();
                          setOver({ group: g.id, index: g.features.length });
                        }}
                        onDrop={(e) => dropFeature(e, g.id, g.features.length)}
                        className={`rounded-lg border border-dashed px-3 py-2.5 text-center text-sm font-medium ${
                          over?.group === g.id &&
                          over.index === g.features.length
                            ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                            : 'border-slate-300 text-slate-500'
                        }`}
                      >
                        {t('features.drop_here')}
                      </li>
                    )}
                  </ol>
                </section>
                {groupOver === layout.length &&
                  gi === layout.length - 1 &&
                  drag?.kind === 'group' && (
                    <div className="h-0.5 rounded bg-emerald-500" />
                  )}
              </Fragment>
            );
          })}
        </div>
        <aside
          aria-label={t('features.ordering_changes')}
          className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 lg:sticky lg:top-6"
        >
          <div className="flex items-center justify-between gap-3">
            <h2 className="font-semibold text-slate-900">
              {t('features.ordering_changes')}
            </h2>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 tabular-nums">
              {entries.length}
            </span>
          </div>
          <p className="text-sm text-slate-500">
            {t(
              entries.length === 0
                ? 'features.ordering_no_changes'
                : 'features.ordering_unsaved',
            )}
          </p>
          <ul
            aria-live="polite"
            className="divide-y divide-slate-100 border-t border-slate-100"
          >
            {entries.map((entry, i) => (
              <li key={i} className="flex items-start gap-3 py-3">
                <span
                  aria-hidden="true"
                  className="mt-1.5 size-1.5 shrink-0 rounded-full bg-emerald-600"
                />
                <div className="min-w-0 flex-1 text-sm text-slate-800">
                  <p>{entryLine(entry)}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {entryDetail(entry)}
                  </p>
                </div>
                {i === entries.length - 1 && (
                  <button
                    type="button"
                    className="shrink-0 text-xs font-medium text-slate-600 hover:text-slate-900"
                    onClick={() => {
                      setLayout(entry.before);
                      setEntries(entries.slice(0, -1));
                    }}
                  >
                    {t('features.ordering_undo')}
                  </button>
                )}
              </li>
            ))}
            {moving && (
              <li className="flex items-start gap-3 py-3">
                <span
                  aria-hidden="true"
                  className="mt-1 size-2 shrink-0 rounded-full border-2 border-emerald-600"
                />
                <div className="min-w-0 text-sm text-slate-800">
                  <p>
                    <strong className="font-semibold">{moving.name}</strong>
                    {moving.target && <> → {moving.target}</>}
                  </p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {t('features.ordering_moving')}
                  </p>
                </div>
              </li>
            )}
          </ul>
          <div className="space-y-2 border-t border-slate-100 pt-4">
            <button
              className="button-primary w-full"
              disabled={!dirty || form.processing}
            >
              {t('features.ordering_save')}
            </button>
            <button
              type="button"
              className="button-secondary w-full"
              disabled={!dirty && entries.length === 0}
              onClick={() => {
                setLayout(clone(original));
                setEntries([]);
              }}
            >
              {t('features.ordering_discard')}
            </button>
          </div>
          <p className="flex gap-2 text-xs leading-5 text-slate-500">
            <svg
              aria-hidden="true"
              viewBox="0 0 20 20"
              className="mt-0.5 size-4 shrink-0"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
            >
              <circle cx="10" cy="10" r="7.5" />
              <path d="M10 9v4.5M10 6.5v.01" strokeLinecap="round" />
            </svg>
            {t('features.ordering_atomic')}
          </p>
          <p className="rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600">
            <strong className="font-semibold">
              {t('features.ordering_keyboard_label')}
            </strong>{' '}
            {keyboard.map((part, i) =>
              part === ':up' || part === ':down' ? (
                <kbd
                  key={i}
                  className="rounded border border-slate-300 bg-white px-1.5 font-sans"
                >
                  {part === ':up' ? '↑' : '↓'}
                </kbd>
              ) : (
                <Fragment key={i}>{part}</Fragment>
              ),
            )}
          </p>
        </aside>
      </form>
    </AdminLayout>
  );
}
