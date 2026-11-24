import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import Dropdown, { Chevron } from '../../../components/admin/Dropdown';
import { Icon } from '../../../components/admin/list/IconButton';
import BulkBar from '../../../components/admin/list/BulkBar';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { giftCardStatus as statusText } from '../../../lib/giftCardStatus';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { GiftCardRow, GiftCardStatus } from '../../../types/giftCards';

const choices: GiftCardStatus[] = ['pending', 'active', 'cancelled'];

function MenuItem({
  children,
  danger = false,
  disabled = false,
  checked,
  onClick,
}: {
  children: ReactNode;
  danger?: boolean;
  disabled?: boolean;
  checked?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role={checked === undefined ? 'menuitem' : 'menuitemradio'}
      aria-checked={checked}
      disabled={disabled}
      onClick={onClick}
      className={
        'flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left hover:bg-slate-100 disabled:text-slate-400 disabled:hover:bg-transparent ' +
        (danger ? 'text-red-700' : '')
      }
    >
      {children}
      {checked && <Icon name="check" className="size-4 text-emerald-700" />}
    </button>
  );
}

export default function GiftCardIndex({
  giftCards,
  filters,
  ...shell
}: AdminShell & {
  giftCards: Page<GiftCardRow>;
  filters: Record<string, string>;
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const apply = useListQuery('/admin/gift-cards', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [removing, setRemoving] = useState<GiftCardRow[] | null>(null);
  // The saved choice of each card, until the page reloads with the server's copy.
  const [chosen, setChosen] = useState<Record<string, boolean>>({});
  const notifies = (row: GiftCardRow) => chosen[row.id] ?? row.notify;
  const { date, time } = useDateDisplay();
  const rows = giftCards.data.filter((r) => selected[r.id]);
  const manage = !!capabilities['giftcards.manage'];
  const canDelete = !!capabilities['giftcards.delete'];
  const setStatus = (row: GiftCardRow, status: GiftCardStatus) =>
    router.patch(
      `/admin/gift-cards/${row.id}/status`,
      { version: row.version, status, notify: notifies(row) },
      { preserveScroll: true },
    );

  return (
    <AdminLayout title={t('giftcards.title')} {...shell}>
      <EditorHeader
        heading={t('giftcards.title')}
        iconKey="gift"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">
              {t('giftcards.intro')}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <Link className="button-primary" href="/admin/gift-cards/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('giftcards.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      {removing && (
        <ConfirmDeleteDialog
          title={t('giftcards.delete_title', { count: removing.length })}
          subtitle={t('giftcards.delete_subtitle')}
          facts={[
            t('giftcards.delete_fact_unusable'),
            t('giftcards.delete_fact_history'),
          ]}
          onCancel={() => setRemoving(null)}
          onConfirm={() => {
            const targets = removing;
            setRemoving(null);
            router.delete('/admin/gift-cards/bulk', {
              data: {
                rows: targets.map((r) => ({ id: r.id, version: r.version })),
              },
              preserveScroll: true,
              onSuccess: () => setSelected({}),
            });
          }}
        />
      )}
      <div className="list-card">
        <FilterBar
          resetHref="/admin/gift-cards"
          active={!!filters.search || !!filters.status}
          search={
            <SearchField
              id="gift-card-search"
              value={search}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
              placeholder={t('giftcards.search')}
            />
          }
          chips={
            <FilterChip
              label={t('giftcards.status')}
              value={filters.status ?? ''}
              options={(
                ['pending', 'active', 'cancelled', 'used'] as GiftCardStatus[]
              ).map((value) => ({
                value,
                label: t(statusText[value][0]),
              }))}
              onChange={(status) => apply({ status })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'created_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={[
                { value: 'code', label: t('giftcards.code') },
                { value: 'created_at', label: t('giftcards.date') },
                { value: 'balance', label: t('giftcards.balance') },
                { value: 'status', label: t('giftcards.status') },
              ]}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
        />
        {canDelete && rows.length > 0 && (
          <BulkBar count={rows.length} onClear={() => setSelected({})}>
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => setRemoving(rows)}
            >
              {t('common.delete')} ({rows.length})
            </button>
          </BulkBar>
        )}
        <div className="relative max-w-full overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {canDelete && (
                  <th className="w-10">
                    <input
                      type="checkbox"
                      aria-label={t('giftcards.select_all')}
                      checked={
                        giftCards.data.length > 0 &&
                        rows.length === giftCards.data.length
                      }
                      onChange={(e) =>
                        setSelected(
                          Object.fromEntries(
                            giftCards.data.map((r) => [r.id, e.target.checked]),
                          ),
                        )
                      }
                    />
                  </th>
                )}
                {['code', 'from', 'to', 'sent_to', 'date', 'balance'].map(
                  (key) => (
                    <th key={key}>{t(`giftcards.${key}`)}</th>
                  ),
                )}
                <th>{t('giftcards.status')}</th>
                <th className="w-24">
                  <span className="sr-only">{t('common.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {giftCards.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-slate-500">
                    {t('giftcards.empty')}
                  </td>
                </tr>
              )}
              {giftCards.data.map((row) => {
                const [statusKey, tone] = statusText[row.status];
                return (
                  <tr key={row.id}>
                    {canDelete && (
                      <td>
                        <input
                          type="checkbox"
                          aria-label={row.code}
                          checked={!!selected[row.id]}
                          onChange={(e) =>
                            setSelected({
                              ...selected,
                              [row.id]: e.target.checked,
                            })
                          }
                        />
                      </td>
                    )}
                    <td>
                      <Link
                        href={`/admin/gift-cards/${row.id}/edit`}
                        className="text-link font-mono font-medium whitespace-nowrap"
                      >
                        {row.code}
                      </Link>
                    </td>
                    <td>{row.sender}</td>
                    <td>{row.recipient}</td>
                    <td className="break-all">{row.email}</td>
                    <td className="whitespace-nowrap">
                      {date.format(new Date(row.created_at))}
                      <span className="block text-xs text-slate-500">
                        {time.format(new Date(row.created_at))}
                      </span>
                    </td>
                    <td className="whitespace-nowrap tabular-nums">
                      <span>{row.balance}</span>
                      {row.partly_used && (
                        <span className="block text-xs text-slate-500">
                          {t('giftcards.of_amount', { amount: row.amount })}
                        </span>
                      )}
                    </td>
                    <td>
                      <StatusBadge tone={tone}>{t(statusKey)}</StatusBadge>
                    </td>
                    <td>
                      <Dropdown
                        label={t('giftcards.row_actions', { code: row.code })}
                        trigger={
                          <>
                            <Icon name="settings" />
                            <Chevron />
                          </>
                        }
                        triggerClassName="button-secondary inline-flex items-center gap-1 px-2.5 py-1.5"
                        panelClassName="w-60 p-1.5"
                        floating
                      >
                        {(close) => (
                          <div className="space-y-0.5 text-sm" role="menu">
                            <Link
                              href={`/admin/gift-cards/${row.id}/edit`}
                              className="block rounded-md px-3 py-2 hover:bg-slate-100"
                              role="menuitem"
                            >
                              {capabilities['giftcards.manage']
                                ? t('common.edit')
                                : t('giftcards.view_card')}
                            </Link>
                            {manage && row.status !== 'used' && (
                              <>
                                <hr className="my-1 border-slate-200" />
                                <p className="px-3 pt-1 pb-0.5 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                  {t('giftcards.set_status')}
                                </p>
                                {choices.map((choice) => (
                                  <MenuItem
                                    key={choice}
                                    checked={row.status === choice}
                                    disabled={row.status === choice}
                                    onClick={() => {
                                      setStatus(row, choice);
                                      close();
                                    }}
                                  >
                                    {t(statusText[choice][0])}
                                  </MenuItem>
                                ))}
                                <label className="flex items-center gap-2 rounded-md border-t border-slate-200 px-3 py-2 pt-3">
                                  <input
                                    type="checkbox"
                                    checked={notifies(row)}
                                    onChange={(e) => {
                                      // Saved straight away, so the editor shows the same choice.
                                      setChosen({
                                        ...chosen,
                                        [row.id]: e.target.checked,
                                      });
                                      router.patch(
                                        `/admin/gift-cards/${row.id}/status`,
                                        {
                                          version: row.version,
                                          status: row.status,
                                          notify: e.target.checked,
                                        },
                                        { preserveScroll: true },
                                      );
                                    }}
                                  />
                                  {t('giftcards.notify')}
                                </label>
                              </>
                            )}
                            {canDelete && (
                              <>
                                <hr className="my-1 border-slate-200" />
                                <MenuItem
                                  danger
                                  onClick={() => {
                                    setRemoving([row]);
                                    close();
                                  }}
                                >
                                  {t('common.delete')}
                                </MenuItem>
                              </>
                            )}
                          </div>
                        )}
                      </Dropdown>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <Pagination
          page={giftCards}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </div>
    </AdminLayout>
  );
}
