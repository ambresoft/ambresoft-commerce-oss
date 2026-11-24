import AdminTabs, { AdminTab } from './AdminTabs';
import { router, usePage } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';
import Dropdown from './Dropdown';
import ConfirmDeleteDialog from './ConfirmDeleteDialog';
import FormErrors from './FormErrors';
import Switch from './Switch';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';
import type { Feature, FeatureOption } from '../../types/features';

type Filter = 'all' | 'active' | 'archived';
type Draft = Record<string, string>;
type NewRow = { key: string; labels: Draft };
type Confirmation = { confirmed: boolean; impact_token: string | null };

const cellClass =
  'field w-full min-w-0 border-emerald-600 bg-white py-2 text-sm shadow-sm';

/**
 * Spreadsheet-style editor of a choice feature's options: one row per option, one column per active language.
 * Label edits and new rows are collected and saved together (atomically, with each option's version guarding the
 * edit); archiving, restoring and deleting stay immediate because they need the impact confirmation.
 */
export default function FeatureOptionsTable({
  feature,
  confirmation,
  details,
  onAttempt,
  onPreconfirm,
}: {
  feature: Feature;
  confirmation: Confirmation;
  /** Extra per-option editor (landing page / swatch data), shown under its row. */
  details?: (option: FeatureOption) => ReactNode;
  /** Registers how to repeat the latest lifecycle action once its impact is confirmed. */
  onAttempt?: (retry: (c: Confirmation) => void) => void;
  /** The next impact request belongs to a deletion the administrator already confirmed. */
  onPreconfirm?: () => void;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const locales = i18n.locales;
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [rows, setRows] = useState<NewRow[]>([]);
  const [editing, setEditing] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const frozen = feature.status === 'archived';
  const saved = (o: FeatureOption, code: string) =>
    o.translations[code]?.label ?? '';
  const value = (o: FeatureOption, code: string) =>
    drafts[o.id]?.[code] ?? saved(o, code);

  const counts = {
    all: feature.options.length,
    active: feature.options.filter((o) => o.status === 'active').length,
    archived: feature.options.filter((o) => o.status === 'archived').length,
  };
  const needle = search.trim().toLowerCase();
  const visible = useMemo(
    () =>
      feature.options.filter(
        (o) =>
          (filter === 'all' || o.status === filter) &&
          (needle === '' ||
            locales.some((l) =>
              (drafts[o.id]?.[l.code] ?? o.translations[l.code]?.label ?? '')
                .toLowerCase()
                .includes(needle),
            )),
      ),
    [feature.options, filter, needle, locales, drafts],
  );

  const modified = Object.keys(drafts).length;
  const filled = rows.filter((row) =>
    Object.values(row.labels).some((v) => v.trim() !== ''),
  );
  const pending = modified + filled.length;

  const edit = (o: FeatureOption, code: string, next: string) =>
    setDrafts((current) => {
      const draft = { ...(current[o.id] ?? {}), [code]: next };
      const same = locales.every(
        (l) => (draft[l.code] ?? saved(o, l.code)) === saved(o, l.code),
      );
      const { [o.id]: _removed, ...rest } = current;
      void _removed;
      return same ? rest : { ...rest, [o.id]: draft };
    });
  const addRow = () => {
    setRows((current) => [
      ...current,
      { key: String(Date.now() + current.length), labels: {} },
    ]);
    setFilter('all');
    setSearch('');
  };
  const discard = () => {
    setDrafts({});
    setRows([]);
    setEditing(null);
    setErrors({});
  };
  const save = () => {
    const changes = [
      ...Object.entries(drafts).map(([id, draft]) => {
        const option = feature.options.find((o) => o.id === id)!;
        return {
          id: Number(id),
          version: option.version,
          translations: Object.fromEntries(
            locales.map((l) => [
              l.code,
              { label: draft[l.code] ?? saved(option, l.code) },
            ]),
          ),
        };
      }),
      ...filled.map((row) => ({
        translations: Object.fromEntries(
          locales.map((l) => [l.code, { label: row.labels[l.code] ?? '' }]),
        ),
      })),
    ];
    router.post(
      '/admin/features/' + feature.id + '/options/batch',
      { feature_version: feature.version, changes },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: () => discard(),
        onError: (e) => setErrors(e as Record<string, string>),
      },
    );
  };
  const lifecycle = (option: FeatureOption, action: string) => {
    const send = (c: Confirmation) =>
      router.post(
        '/admin/features/' + feature.id + '/options/' + option.id,
        {
          version: option.version,
          feature_version: feature.version,
          action,
          translations: option.translations,
          confirmed: c.confirmed,
          ...(c.impact_token ? { impact_token: c.impact_token } : {}),
        },
        {
          preserveScroll: true,
          onSuccess: () => setErrors({}),
          onError: (e) => {
            const { confirmation: _c, ...rest } = e as Record<string, string>;
            void _c;
            setErrors(rest);
          },
        },
      );
    onAttempt?.(send);
    send(confirmation);
  };
  const [removing, setRemoving] = useState<FeatureOption | null>(null);
  const columns = 4 + locales.length - 1;
  return (
    <div className="space-y-3">
      {removing && (
        <ConfirmDeleteDialog
          title={t('features.delete_option_title', { name: removing.label })}
          facts={
            (removing.products ?? 0) > 0
              ? [
                  t('features.delete_products_lost', {
                    count: removing.products ?? 0,
                    name: removing.label,
                  }),
                ]
              : []
          }
          onArchive={
            removing.status === 'active'
              ? () => {
                  const option = removing;
                  setRemoving(null);
                  lifecycle(option, 'archive');
                }
              : undefined
          }
          onCancel={() => setRemoving(null)}
          onConfirm={() => {
            const option = removing;
            setRemoving(null);
            lifecycle(option, 'delete');
            onPreconfirm?.();
          }}
        />
      )}
      <FormErrors errors={errors} />
      <div className="rounded-xl border border-slate-200 bg-white">
        <div className="flex flex-wrap items-center gap-3 p-4">
          <input
            type="search"
            className="field min-w-48 flex-1"
            placeholder={t('features.options_search')}
            aria-label={t('features.options_search')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <AdminTabs label={t('common.status')} className="mb-0">
            {(['all', 'active', 'archived'] as const).map((item) => (
              <AdminTab
                key={item}
                iconKey={item}
                aria-selected={filter === item}
                onClick={() => setFilter(item)}
              >
                {t('features.' + (item === 'all' ? 'all' : item))}{' '}
                <span className="ml-1 rounded-full bg-slate-200 px-1.5 text-xs">
                  {counts[item]}
                </span>
              </AdminTab>
            ))}
          </AdminTabs>
          {!frozen && (
            <button
              type="button"
              className="button-primary ml-auto"
              onClick={addRow}
            >
              {t('features.add_option')}
            </button>
          )}
        </div>
        <table className="data-table w-full">
          <thead>
            <tr>
              {locales.map((l) => (
                <th key={l.code} className="text-left">
                  {l.name}
                </th>
              ))}
              <th className="text-left">{t('features.options_products')}</th>
              <th className="text-left">{t('common.status')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {visible.length === 0 && rows.length === 0 && (
              <tr>
                <td
                  colSpan={columns}
                  className="py-10 text-center text-slate-500"
                >
                  {t('features.options_empty')}
                </td>
              </tr>
            )}
            {visible.map((o) => {
              const archived = o.status === 'archived';
              const locked = frozen || archived;
              const changed = drafts[o.id] !== undefined;
              return (
                <RowGroup key={o.id + ':' + o.version}>
                  <tr className={changed ? 'bg-emerald-50' : undefined}>
                    {locales.map((l) => {
                      const text = value(o, l.code);
                      const key = o.id + ':' + l.code;
                      const missing = text.trim() === '' && !archived;
                      if (!locked && (editing === key || changed)) {
                        return (
                          <td key={l.code}>
                            <input
                              className={cellClass}
                              autoFocus={editing === key}
                              aria-label={o.label + ' · ' + l.name}
                              value={text}
                              onChange={(e) => edit(o, l.code, e.target.value)}
                              onBlur={() => setEditing(null)}
                            />
                          </td>
                        );
                      }
                      return (
                        <td key={l.code}>
                          {missing ? (
                            <button
                              type="button"
                              disabled={locked}
                              onClick={() => setEditing(key)}
                              className="rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800"
                            >
                              ⚠ {t('features.options_missing')}
                            </button>
                          ) : (
                            <button
                              type="button"
                              disabled={locked}
                              onClick={() => setEditing(key)}
                              className={
                                'w-full rounded-md py-2 text-left ' +
                                (archived
                                  ? 'text-slate-500'
                                  : 'font-medium hover:bg-slate-50')
                              }
                            >
                              {text}
                            </button>
                          )}
                        </td>
                      );
                    })}
                    <td className="tabular-nums text-emerald-700">
                      {o.products ?? 0}
                    </td>
                    <td>
                      <div className="flex items-center gap-2 text-sm">
                        <Switch
                          checked={!archived}
                          disabled={frozen}
                          label={o.label}
                          onChange={(on) =>
                            lifecycle(o, on ? 'restore' : 'archive')
                          }
                        />
                        {t('features.' + (archived ? 'archived' : 'active'))}
                      </div>
                    </td>
                    <td className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        {changed && (
                          <span className="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800">
                            {t('features.options_modified')}
                          </span>
                        )}
                        {!frozen && (
                          <Dropdown
                            label={t('features.options_actions', {
                              name: o.label,
                            })}
                            trigger={<span aria-hidden="true">•••</span>}
                            triggerClassName="button-secondary px-3"
                            panelClassName="w-56 p-1.5"
                          >
                            {(close) => (
                              <div className="space-y-0.5 text-sm">
                                {details && !archived && (
                                  <MenuItem
                                    onClick={() => {
                                      setOpen(open === o.id ? null : o.id);
                                      close();
                                    }}
                                  >
                                    {t('features.options_details')}
                                  </MenuItem>
                                )}
                                <MenuItem
                                  onClick={() => {
                                    lifecycle(
                                      o,
                                      archived ? 'restore' : 'archive',
                                    );
                                    close();
                                  }}
                                >
                                  {t(
                                    archived
                                      ? 'features.activate'
                                      : 'features.archive',
                                  )}
                                </MenuItem>
                                <hr className="my-1 border-slate-200" />
                                <MenuItem
                                  danger
                                  onClick={() => {
                                    setRemoving(o);
                                    close();
                                  }}
                                >
                                  × {t('features.delete')}…
                                </MenuItem>
                              </div>
                            )}
                          </Dropdown>
                        )}
                      </div>
                    </td>
                  </tr>
                  {details && open === o.id && (
                    <tr>
                      <td colSpan={columns} className="bg-slate-50 p-4">
                        {details(o)}
                      </td>
                    </tr>
                  )}
                </RowGroup>
              );
            })}
            {rows.map((row, index) => (
              <tr key={row.key} className="bg-emerald-50">
                {locales.map((l, position) => (
                  <td key={l.code}>
                    <input
                      className={cellClass}
                      autoFocus={position === 0 && index === rows.length - 1}
                      aria-label={t('features.add_option') + ' · ' + l.name}
                      placeholder={l.name + '…'}
                      value={row.labels[l.code] ?? ''}
                      onChange={(e) =>
                        setRows((current) =>
                          current.map((r) =>
                            r.key === row.key
                              ? {
                                  ...r,
                                  labels: {
                                    ...r.labels,
                                    [l.code]: e.target.value,
                                  },
                                }
                              : r,
                          ),
                        )
                      }
                    />
                  </td>
                ))}
                <td>
                  <span className="rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">
                    {t('features.options_new')}
                  </span>
                </td>
                <td className="text-sm">{t('features.active')}</td>
                <td className="text-right">
                  <button
                    type="button"
                    className="text-sm text-slate-600 hover:text-slate-900"
                    onClick={() =>
                      setRows((current) =>
                        current.filter((r) => r.key !== row.key),
                      )
                    }
                  >
                    × {t('features.options_remove')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 p-4 text-sm text-slate-500">
          <span>{t('features.options_hint')}</span>
          <span>
            {t('features.options_count', { count: counts.all + rows.length })}
          </span>
        </div>
      </div>
      {pending > 0 && (
        <div
          role="status"
          className="sticky bottom-4 z-20 mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-900 px-5 py-3 text-white shadow-xl"
        >
          <span>
            <strong>{t('features.options_pending', { count: pending })}</strong>{' '}
            ·{' '}
            {t('features.options_breakdown', {
              new: filled.length,
              modified,
            })}
          </span>
          <span className="flex gap-2">
            <button
              type="button"
              className="rounded-lg border border-white/40 px-4 py-2 text-sm"
              disabled={busy}
              onClick={discard}
            >
              {t('features.options_discard')}
            </button>
            <button
              type="button"
              className="button-primary"
              disabled={busy}
              onClick={save}
            >
              {t(busy ? 'common.wait' : 'features.options_save')}
            </button>
          </span>
        </div>
      )}
    </div>
  );
}

function RowGroup({ children }: { children: ReactNode }) {
  return <>{children}</>;
}

function MenuItem({
  children,
  danger = false,
  onClick,
}: {
  children: ReactNode;
  danger?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        'block w-full rounded-md px-3 py-2 text-left hover:bg-slate-100 ' +
        (danger ? 'text-red-700' : '')
      }
    >
      {children}
    </button>
  );
}
