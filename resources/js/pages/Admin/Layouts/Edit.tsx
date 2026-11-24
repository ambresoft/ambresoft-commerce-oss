import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import Select from '../../../components/admin/Select';
import Switch from '../../../components/admin/Switch';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import DragGrip from '../../../components/admin/list/DragGrip';
import { validLayoutGrids } from '../../../lib/layoutGrids';
import { useTranslation } from '../../../lib/useTranslation';
import type { SharedProps } from '../../../types/page';
import type { AdminShell } from '../../../types/admin';
import type {
  BlockConfig,
  BlockDefinition,
  Grid,
  Layout,
  LayoutOptions,
  LayoutTab,
  PageOption,
  PageSettings,
  Placement,
} from '../../../types/layouts';
import BlockDialog from './BlockDialog';
import AddBlockDialog from './AddBlockDialog';
import LayoutSettingsDialog from './LayoutSettingsDialog';

type Props = AdminShell & {
  layout: Layout;
  sharedLayout: Layout | null;
  definitions: Record<string, BlockDefinition>;
  tabs: LayoutTab[];
  pageDefinition: { regions: string[]; custom: boolean };
  pageKind: string;
  pageSettings: PageSettings;
  pageOptions: PageOption[];
  theme: { name: string };
  defaultLocale: string;
  options: LayoutOptions;
};
const order = <T extends { position: number }>(items: T[]) =>
  [...items].sort((a, b) => a.position - b.position);
export default function LayoutEditor({
  layout,
  sharedLayout,
  definitions,
  tabs,
  pageDefinition,
  pageKind,
  pageSettings,
  pageOptions,
  theme,
  defaultLocale,
  options,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const manage = !!capabilities['layouts.manage'];
  const form = useForm({
    version: layout.version,
    grids: layout.grids,
    placements: layout.placements,
    overrides: layout.overrides,
  });
  useDirtyForm(form);
  const [editing, setEditing] = useState<{
    placement: Placement;
    inherited?: boolean;
  } | null>(null);
  const [gridEdit, setGridEdit] = useState<Grid | null>(null);
  const [remove, setRemove] = useState<{
    kind: 'grid' | 'block';
    id: string;
  } | null>(null);
  const [error, setError] = useState('');
  const [dragging, setDragging] = useState<{
    kind: 'block' | 'grid';
    id: string;
  } | null>(null);
  const [dropTarget, setDropTarget] = useState<string | null>(null);
  const finishDrag = () => {
    setDragging(null);
    setDropTarget(null);
  };
  const [addingTo, setAddingTo] = useState<Grid | null>(null);
  const [pageDialog, setPageDialog] = useState<'edit' | 'new' | null>(null);
  const path = `/admin/layouts/${layout.page_type}`;
  const editable = Object.entries(definitions).filter(
    ([, d]) =>
      d.pages.includes(pageKind) &&
      d.creatable !== false &&
      d.policy.configure &&
      d.canManage,
  );
  const copySources = [
    ...form.data.placements,
    ...(sharedLayout?.placements ?? []),
  ].filter(
    (p) =>
      definitions[p.block.type]?.policy.configure &&
      definitions[p.block.type]?.canManage,
  );
  const updatePlacement = (p: Placement) => {
    const rest = form.data.placements.filter((item) => item.id !== p.id);
    const siblings = order(rest.filter((item) => item.grid_id === p.grid_id));
    siblings.splice(
      Math.min(Math.max(p.position - 1, 0), siblings.length),
      0,
      p,
    );
    form.setData('placements', [
      ...rest.filter((item) => item.grid_id !== p.grid_id),
      ...siblings.map((item, n) => ({ ...item, position: n + 1 })),
    ]);
  };
  const cloneBlock = (b: BlockConfig): BlockConfig => ({
    ...structuredClone(b),
    id: crypto.randomUUID(),
  });
  const addBlock = (
    g: Grid,
    source?: BlockConfig,
    selectedType?: string,
    template?: string,
  ) => {
    const type = source?.type ?? selectedType ?? editable[0]?.[0];
    if (!type) return;
    const definition = definitions[type];
    const block = source
      ? cloneBlock(source)
      : {
          id: crypto.randomUUID(),
          type,
          name: t(definition.label),
          template: template ?? definition.templates[0],
          css_class: '',
          settings: { ...definition.defaults },
          translations: Object.fromEntries(
            i18n.locales.map((l) => [l.code, { title: '', html: '' }]),
          ),
          selections: Object.fromEntries(
            definition.fields
              .filter((f) => f.kind === 'selection')
              .map((f) => [f.key, []]),
          ),
        };
    setEditing({
      placement: {
        id: crypto.randomUUID(),
        grid_id: g.id,
        position:
          form.data.placements.filter((p) => p.grid_id === g.id).length + 1,
        enabled: true,
        mobile: true,
        tablet: true,
        desktop: true,
        block,
      },
    });
  };
  const addGrid = (region: string, parent: Grid | null) => {
    const siblings = form.data.grids.filter(
      (g) => g.region === region && g.parent_id === (parent?.id ?? null),
    );
    setGridEdit({
      id: crypto.randomUUID(),
      parent_id: parent?.id ?? null,
      region,
      width: parent?.width ?? 16,
      row: Math.max(0, ...siblings.map((g) => g.row)) + 1,
      position: 1,
    });
  };
  const descendants = (id: string): string[] => [
    id,
    ...form.data.grids
      .filter((g) => g.parent_id === id)
      .flatMap((g) => descendants(g.id)),
  ];
  const confirmRemove = () => {
    if (!remove) return;
    if (remove.kind === 'block')
      form.setData(
        'placements',
        form.data.placements.filter((p) => p.id !== remove.id),
      );
    else {
      const ids = descendants(remove.id);
      if (
        form.data.placements.some(
          (p) =>
            ids.includes(p.grid_id) &&
            (!(definitions[p.block.type]?.policy.delete ?? true) ||
              !(definitions[p.block.type]?.canManage ?? true)),
        )
      ) {
        setError(t('layouts.protected'));
        setRemove(null);
        return;
      }
      form.setData({
        ...form.data,
        grids: form.data.grids.filter((g) => !ids.includes(g.id)),
        placements: form.data.placements.filter(
          (p) => !ids.includes(p.grid_id),
        ),
      });
    }
    setRemove(null);
  };
  const placeGrid = (id: string, target: Grid) => {
    const moving = form.data.grids.find((g) => g.id === id);
    if (!moving || id === target.id) return;
    const siblings = form.data.grids
      .filter(
        (g) => g.region === target.region && g.parent_id === target.parent_id,
      )
      .sort((a, b) => a.row - b.row || a.position - b.position);
    let next: Grid[];
    if (
      moving.region === target.region &&
      moving.parent_id === target.parent_id
    ) {
      // Keep the existing row slots: moving grids never silently collapses deliberate rows.
      const slots = siblings.map((g) => ({ row: g.row, position: g.position }));
      const from = siblings.findIndex((g) => g.id === id);
      const to = siblings.findIndex((g) => g.id === target.id);
      siblings.splice(to, 0, siblings.splice(from, 1)[0]!);
      const placed = siblings.map((g, n) => ({ ...g, ...slots[n] }));
      next = form.data.grids.map(
        (g) => placed.find((item) => item.id === g.id) ?? g,
      );
    } else {
      const row = siblings.filter((g) => g.row === target.row);
      row.splice(
        row.findIndex((g) => g.id === target.id),
        0,
        {
          ...moving,
          parent_id: target.parent_id,
          region: target.region,
          row: target.row,
        },
      );
      const placed = row.map((g, n) => ({ ...g, position: n + 1 }));
      const descendantIds = descendants(id);
      next = form.data.grids
        .filter((g) => g.id !== id)
        .map(
          (g) =>
            placed.find((item) => item.id === g.id) ??
            (descendantIds.includes(g.id)
              ? { ...g, region: target.region }
              : g),
        );
      next.push(placed.find((g) => g.id === id)!);
    }
    const fixed = form.data.placements
      .filter(
        (p) =>
          !definitions[p.block.type]?.policy.move ||
          !definitions[p.block.type]?.canManage,
      )
      .map((p) => p.grid_id);
    if (descendants(id).some((gridId) => fixed.includes(gridId))) {
      setError(t('layouts.protected'));
      return;
    }
    if (!validLayoutGrids(next)) {
      setError(t('layouts.invalid_grids'));
      return;
    }
    form.setData('grids', next);
    setError('');
  };
  const dropBlock = (grid: string, position: number) => {
    if (dragging?.kind !== 'block') return;
    const p = form.data.placements.find((item) => item.id === dragging.id);
    if (
      p &&
      definitions[p.block.type]?.policy.move &&
      definitions[p.block.type]?.canManage
    )
      updatePlacement({ ...p, grid_id: grid, position });
    finishDrag();
  };
  const renderGrid = (g: Grid, index: number, depth = 0): React.ReactNode => {
    const placements = order(
      form.data.placements.filter((p) => p.grid_id === g.id),
    );
    const children = form.data.grids
      .filter((child) => child.parent_id === g.id)
      .sort((a, b) => a.row - b.row || a.position - b.position);
    return (
      <div
        key={g.id}
        data-grid-id={g.id}
        draggable={manage && !form.processing}
        onDragStart={(e) => {
          e.stopPropagation();
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', g.id);
          setDragging({ kind: 'grid', id: g.id });
        }}
        onDragEnd={finishDrag}
        onDragOver={(e) => {
          if (!dragging) return;
          e.preventDefault();
          e.stopPropagation();
          e.dataTransfer.dropEffect = 'move';
          setDropTarget(g.id);
        }}
        onDrop={(e) => {
          if (!dragging) return;
          e.preventDefault();
          e.stopPropagation();
          if (dragging.kind === 'block') dropBlock(g.id, placements.length + 1);
          else {
            placeGrid(dragging.id, g);
            finishDrag();
          }
        }}
        className={`lx-grid ${dragging?.id === g.id ? 'opacity-50' : ''} ${dropTarget === g.id ? 'outline-2 outline-emerald-600' : ''}`}
        style={{ gridColumn: `span ${g.width}` }}
      >
        <div className="lx-grid-title">
          {manage && (
            <button
              type="button"
              className="cursor-grab rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
              aria-label={t('layouts.drag_grid', { name: index + 1 })}
              title={t('layouts.drag_grid', { name: index + 1 })}
              onKeyDown={(e) => {
                if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
                e.preventDefault();
                const siblings = form.data.grids
                  .filter(
                    (item) =>
                      item.parent_id === g.parent_id &&
                      item.region === g.region,
                  )
                  .sort((a, b) => a.row - b.row || a.position - b.position);
                const to =
                  siblings.findIndex((item) => item.id === g.id) +
                  (e.key === 'ArrowUp' ? -1 : 1);
                if (siblings[to]) placeGrid(g.id, siblings[to]);
              }}
            >
              <DragGrip />
            </button>
          )}
          <span>
            {t('layouts.grid')} {index + 1}{' '}
            <span className="text-slate-500">
              (span {g.width}) · {t('layouts.row')} {g.row}
              {depth > 0 ? ` · ${t('layouts.nested')}` : ''}
            </span>
          </span>
        </div>
        <div className="space-y-2">
          {placements.map((p, n) => {
            const d = definitions[p.block.type];
            return (
              <div
                key={p.id}
                data-placement-id={p.id}
                draggable={
                  manage && d?.canManage && d.policy.move && !form.processing
                }
                onDragStart={(e) => {
                  e.stopPropagation();
                  e.dataTransfer.effectAllowed = 'move';
                  e.dataTransfer.setData('text/plain', p.id);
                  setDragging({ kind: 'block', id: p.id });
                }}
                onDragEnd={finishDrag}
                onDragOver={(e) => {
                  if (dragging?.kind !== 'block') return;
                  e.preventDefault();
                  e.stopPropagation();
                  e.dataTransfer.dropEffect = 'move';
                  setDropTarget(p.id);
                }}
                onDrop={(e) => {
                  if (dragging?.kind !== 'block') return;
                  e.preventDefault();
                  e.stopPropagation();
                  dropBlock(g.id, n + 1);
                }}
                className={`lx-block ${p.enabled ? '' : 'is-off'} ${dragging?.id === p.id ? 'opacity-50' : ''} ${dropTarget === p.id ? 'outline-2 outline-emerald-600' : ''}`}
              >
                <div className="lx-block-head">
                  {manage && d?.canManage && d.policy.move && (
                    <button
                      type="button"
                      className="cursor-grab rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                      aria-label={t('layouts.drag_block', {
                        name: p.block.name,
                      })}
                      title={t('layouts.drag_block', { name: p.block.name })}
                      onKeyDown={(e) => {
                        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')
                          return;
                        e.preventDefault();
                        const to = n + (e.key === 'ArrowUp' ? -1 : 1);
                        if (to >= 0 && to < placements.length)
                          updatePlacement({ ...p, position: to + 1 });
                      }}
                    >
                      <DragGrip />
                    </button>
                  )}
                  <span className="lx-block-icon">
                    <Icon name="box" />
                  </span>
                  <div className="min-w-0 mr-auto">
                    <p className="break-words text-sm font-medium">
                      {p.block.name}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      #{p.block.id.slice(0, 4)} ·{' '}
                      {d ? t(d.label) : t('layouts.unavailable')} ·{' '}
                      {t(p.enabled ? 'layouts.enabled' : 'layouts.disabled')}
                      {d && !d.policy.delete
                        ? ` · ${t('layouts.protected_label')}`
                        : ''}
                    </p>
                  </div>
                </div>
                {manage && (
                  <div className="lx-block-bar">
                    {d && d.policy.configure && d.canManage && (
                      <IconButton
                        bare
                        icon="settings"
                        label={t('layouts.configure_block')}
                        onClick={() => setEditing({ placement: p })}
                      />
                    )}
                    {d && (
                      <Switch
                        label={`${t('layouts.enabled')}: ${p.block.name}`}
                        checked={p.enabled}
                        disabled={!d.policy.disable || !d.canManage}
                        onChange={(enabled) =>
                          updatePlacement({ ...p, enabled })
                        }
                      />
                    )}
                    <span className="ml-auto flex items-center gap-0.5">
                      {d?.canManage && d.policy.configure && (
                        <IconButton
                          bare
                          icon="copy"
                          label={t('layouts.duplicate')}
                          onClick={() =>
                            updatePlacement({
                              ...p,
                              id: crypto.randomUUID(),
                              position: n + 2,
                              block: cloneBlock(p.block),
                            })
                          }
                        />
                      )}
                      {(!d || (d.policy.delete && d.canManage)) && (
                        <IconButton
                          bare
                          danger
                          icon="trash"
                          label={t('layouts.delete_block')}
                          onClick={() => setRemove({ kind: 'block', id: p.id })}
                        />
                      )}
                    </span>
                  </div>
                )}
              </div>
            );
          })}
        </div>
        {placements.length === 0 && children.length === 0 && (
          <p className="py-5 text-center text-xs text-slate-500">
            {t('layouts.empty_grid')}
          </p>
        )}
        {manage && (
          <div className="lx-grid-bar">
            <IconButton
              bare
              icon="plus"
              label={t('layouts.add_block')}
              onClick={() => setAddingTo(g)}
            />
            <IconButton
              bare
              icon="grid"
              label={t('layouts.add_child_grid')}
              onClick={() => addGrid(g.region, g)}
            />
            <IconButton
              bare
              icon="settings"
              label={t('layouts.edit_grid')}
              onClick={() => setGridEdit(g)}
            />
            <span className="ml-auto">
              <IconButton
                bare
                danger
                icon="trash"
                label={t('layouts.delete_grid')}
                onClick={() => setRemove({ kind: 'grid', id: g.id })}
              />
            </span>
          </div>
        )}
        {children.length > 0 && (
          <div className="mt-4 space-y-3">
            {[...new Set(children.map((c) => c.row))].map((row) => (
              <div
                key={row}
                className="grid gap-3"
                style={{
                  gridTemplateColumns: `repeat(${g.width}, minmax(0,1fr))`,
                }}
              >
                {children
                  .filter((c) => c.row === row)
                  .map((c) =>
                    renderGrid(c, form.data.grids.indexOf(c), depth + 1),
                  )}
              </div>
            ))}
          </div>
        )}
      </div>
    );
  };
  return (
    <AdminLayout title={t('layouts.title')} {...shell}>
      <EditorHeader
        heading={t('layouts.title')}
        iconKey="layouts"
        status={
          <>
            <span className="text-sm text-slate-500">
              {t(form.isDirty ? 'layouts.unsaved' : 'layouts.saved_state')}
            </span>
          </>
        }
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">
              {t('layouts.intro')} · {theme.name}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <EditorActionButton
                type="button"

                disabled={form.processing}
                onClick={() =>
                  form.put(`/admin/layouts/${layout.page_type}`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                      const saved = (
                        page.props as unknown as { layout: Layout }
                      ).layout;
                      const next = {
                        version: saved.version,
                        grids: saved.grids,
                        placements: saved.placements,
                        overrides: saved.overrides,
                      };
                      form.setData(next);
                      form.setDefaults(next);
                    },
                  })
                }
              >
                {t('layouts.save')}
              </EditorActionButton>
            )}
          </>
        }
      />

      <AdminTabs
        id="layout-page-tabs"
        label={t('layouts.title')}
        actions={
          <>
            <button
              type="button"
              className="icon-button"
              aria-label={t('layouts.page_settings')}
              title={t('layouts.page_settings')}
              onClick={() => setPageDialog('edit')}
            >
              <Icon name="settings" />
            </button>
            {manage && (
              <button
                type="button"
                className="icon-button"
                aria-label={t('layouts.add_layout')}
                title={t('layouts.add_layout')}
                onClick={() => setPageDialog('new')}
              >
                <Icon name="plus" className="size-5" />
              </button>
            )}
          </>
        }
      >
        {tabs.map((tab) => (
          <AdminTab
            key={tab.key}
            href={`/admin/layouts/${tab.key}`}
            aria-selected={layout.page_type === tab.key}
            icon={false}
          >
            {tab.translate ? t(tab.label) : tab.label}
          </AdminTab>
        ))}
      </AdminTabs>
      <FormErrors
        errors={{ ...form.errors, ...(error ? { grids: error } : {}) }}
      />
      <p className="mb-5 text-sm text-slate-500">{t('layouts.grid_help')}</p>
      {sharedLayout && (
        <details className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
          <summary className="cursor-pointer font-medium">
            {t('layouts.shared_regions')}
          </summary>
          <p className="my-3 text-sm text-slate-500">
            {t('layouts.inheritance_help')}
          </p>
          <Link href="/admin/layouts/default" className="text-link text-sm">
            {t('layouts.edit_default')}
          </Link>
          <div className="mt-4 space-y-2">
            {sharedLayout.placements.map((p) => {
              const o = form.data.overrides.find(
                (o) => o.placement_id === p.id,
              );
              const d = definitions[p.block.type];
              return (
                <div
                  key={p.id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3"
                >
                  <span className="text-sm">
                    {p.block.name}
                    {o ? ` · ${t('layouts.overridden')}` : ''}
                  </span>
                  {manage && d?.policy.configure && (
                    <div className="flex items-center gap-3">
                      {o && (
                        <button
                          type="button"
                          className="text-link text-xs"
                          onClick={() =>
                            form.setData(
                              'overrides',
                              form.data.overrides.filter(
                                (item) => item.placement_id !== p.id,
                              ),
                            )
                          }
                        >
                          {t('layouts.reset_override')}
                        </button>
                      )}
                      <IconButton
                        icon="edit"
                        label={t('layouts.override')}
                        onClick={() =>
                          setEditing({
                            placement: {
                              ...p,
                              block: o?.block ?? cloneBlock(p.block),
                              enabled: o?.enabled ?? p.enabled,
                            },
                            inherited: true,
                          })
                        }
                      />
                      <Switch
                        label={`${t('layouts.enabled')}: ${p.block.name}`}
                        checked={o?.enabled ?? p.enabled}
                        onChange={(enabled) =>
                          form.setData('overrides', [
                            ...form.data.overrides.filter(
                              (item) => item.placement_id !== p.id,
                            ),
                            {
                              placement_id: p.id,
                              enabled,
                              block: o?.block ?? null,
                            },
                          ])
                        }
                      />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </details>
      )}
      <div className="space-y-6">
        {pageDefinition.regions.map((region) => {
          const roots = form.data.grids
            .filter((g) => g.region === region && !g.parent_id)
            .sort((a, b) => a.row - b.row || a.position - b.position);
          return (
            <section
              key={region}
              className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5"
            >
              <div className="mb-4 flex items-center justify-between gap-3">
                <h2 className="font-semibold">
                  {t(`layouts.region_${region}`)}
                </h2>
                {manage && (
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() => addGrid(region, null)}
                  >
                    {t('layouts.add_grid')}
                  </button>
                )}
              </div>
              {roots.length === 0 && (
                <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                  {t('layouts.empty_region')}
                </p>
              )}
              <div className="lx-columns space-y-2 overflow-x-auto">
                <div
                  aria-hidden="true"
                  className="grid min-w-[640px] grid-cols-[repeat(16,minmax(0,1fr))] gap-3 text-center text-[10px] text-slate-500"
                >
                  {Array.from({ length: 16 }, (_, n) => (
                    <span key={n} className="border-x border-slate-200 py-1">
                      {n + 1}
                    </span>
                  ))}
                </div>
                {[...new Set(roots.map((g) => g.row))].map((row) => (
                  <div
                    key={row}
                    className="grid min-w-[640px] grid-cols-[repeat(16,minmax(0,1fr))] gap-3"
                  >
                    {roots
                      .filter((g) => g.row === row)
                      .map((g) => renderGrid(g, form.data.grids.indexOf(g)))}
                  </div>
                ))}
              </div>
            </section>
          );
        })}
      </div>
      {addingTo && (
        <AddBlockDialog
          definitions={Object.fromEntries(editable)}
          sources={copySources}
          onClose={() => setAddingTo(null)}
          onCreate={(type, template) => {
            addBlock(addingTo, undefined, type, template);
            setAddingTo(null);
          }}
          onExisting={(block) => {
            addBlock(addingTo, block);
            setAddingTo(null);
          }}
        />
      )}
      {editing && (
        <BlockDialog
          key={editing.placement.id}
          placement={editing.placement}
          definition={definitions[editing.placement.block.type]}
          grids={form.data.grids}
          options={options}
          path={path}
          languages={i18n.locales}
          defaultLocale={defaultLocale}
          inherited={editing.inherited}
          pageKind={pageKind}
          onClose={() => setEditing(null)}
          onApply={(p) => {
            if (editing.inherited)
              form.setData('overrides', [
                ...form.data.overrides.filter((o) => o.placement_id !== p.id),
                { placement_id: p.id, enabled: p.enabled, block: p.block },
              ]);
            else updatePlacement(p);
            setEditing(null);
          }}
        />
      )}
      {gridEdit && (
        <GridDialog
          error={error}
          grid={gridEdit}
          grids={form.data.grids}
          regions={pageDefinition.regions}
          onClose={() => setGridEdit(null)}
          onApply={(g) => {
            const children = descendants(g.id);
            const next = [
              ...form.data.grids
                .filter((item) => item.id !== g.id)
                .map((item) =>
                  children.includes(item.id)
                    ? { ...item, region: g.region }
                    : item,
                ),
              g,
            ];
            if (!validLayoutGrids(next)) {
              setError(t('layouts.invalid_grids'));
              return;
            }
            form.setData('grids', next);
            setError('');
            setGridEdit(null);
          }}
        />
      )}
      {pageDialog && (
        <LayoutSettingsDialog
          key={pageDialog + layout.page_type}
          layoutKey={pageDialog === 'edit' ? layout.page_type : undefined}
          settings={pageDialog === 'edit' ? pageSettings : null}
          pageOptions={pageOptions}
          languages={i18n.locales}
          defaultLocale={defaultLocale}
          manage={manage}
          onClose={() => setPageDialog(null)}
        />
      )}
      {remove && (
        <ConfirmDeleteDialog
          title={t(
            remove.kind === 'grid'
              ? 'layouts.delete_grid'
              : 'layouts.delete_block',
          )}
          facts={[
            t(
              remove.kind === 'grid'
                ? 'layouts.delete_grid_help'
                : 'layouts.delete_block_help',
            ),
          ]}
          onCancel={() => setRemove(null)}
          onConfirm={confirmRemove}
        />
      )}
    </AdminLayout>
  );
}

function GridDialog({
  error,
  grid,
  grids,
  regions,
  onApply,
  onClose,
}: {
  error: string;
  grid: Grid;
  grids: Grid[];
  regions: string[];
  onApply: (g: Grid) => void;
  onClose: () => void;
}) {
  const t = useTranslation();
  const [draft, setDraft] = useState(grid);
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    ref.current?.showModal();
  }, []);
  return (
    <dialog
      ref={ref}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      aria-labelledby="grid-title"
      className="m-auto w-[min(32rem,95vw)] rounded-xl border border-slate-200 bg-white p-6 text-slate-900 backdrop:bg-black/50"
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          onApply(draft);
        }}
      >
        {error && (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        )}
        <h2 id="grid-title" className="font-semibold">
          {t('layouts.edit_grid')}
        </h2>
        <label className="block text-sm">
          {t('layouts.region')}
          <Select
            value={draft.region}
            aria-label={t('layouts.region')}
            options={regions.map((value) => ({
              value,
              label: t(`layouts.region_${value}`),
            }))}
            onChange={(region) =>
              setDraft({ ...draft, region, parent_id: null })
            }
          />
        </label>
        <label className="block text-sm">
          {t('layouts.parent')}
          <Select
            aria-label={t('layouts.parent')}
            value={draft.parent_id ?? ''}
            options={[
              { value: '', label: t('layouts.region_root') },
              ...grids
                .filter((g) => g.id !== draft.id && g.region === draft.region)
                .map((g, n) => ({
                  value: g.id,
                  label: `${t('layouts.grid')} ${n + 1} (${g.width}/16)`,
                })),
            ]}
            onChange={(value) =>
              setDraft({ ...draft, parent_id: value || null })
            }
          />
        </label>
        {(['width', 'row', 'position'] as const).map((key) => (
          <FormField
            key={key}
            id={`grid-${key}`}
            label={t(`layouts.${key}`)}
            type="number"
            min={1}
            max={key === 'width' ? 16 : 1000}
            required
            value={draft[key]}
            onChange={(e) =>
              setDraft({ ...draft, [key]: Number(e.target.value) })
            }
          />
        ))}
        <p className="text-sm text-slate-500">{t('layouts.grid_help')}</p>
        <div className="flex justify-end gap-3">
          <button type="button" className="button-secondary" onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button className="button-primary">{t('layouts.apply')}</button>
        </div>
      </form>
    </dialog>
  );
}
