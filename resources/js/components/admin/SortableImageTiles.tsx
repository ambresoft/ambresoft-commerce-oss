import { useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import ConfirmDeleteDialog from './ConfirmDeleteDialog';
import FormErrors from './FormErrors';
import DragGrip from './list/DragGrip';
import { Icon } from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';

/**
 * An ordered grid of picture tiles saved one request at a time: drag or arrow keys reorder (the whole list is sent),
 * the trash button removes after confirmation and a dashed tile adds. Payment icons and footer links use it.
 */
export default function SortableImageTiles<T extends { id: string }>({
  items,
  label,
  name,
  children,
  orderUrl,
  orderData = (ids) => ({ ids }),
  removeUrl,
  removeTitle,
  removeHint,
  onEdit,
  onAdd,
  addLabel,
  limit,
  limitText,
  disabled,
  onBusyChange,
  after,
}: {
  items: T[];
  label: string;
  name: (item: T) => string;
  children: (item: T) => ReactNode;
  orderUrl: string;
  orderData?: (ids: string[]) => Record<string, unknown>;
  removeUrl: (item: T) => string;
  removeTitle: string;
  removeHint: string;
  onEdit?: (item: T) => void;
  onAdd: () => void;
  addLabel: string;
  limit: number;
  limitText: string;
  disabled: boolean;
  onBusyChange: (busy: boolean) => void;
  after?: ReactNode;
}) {
  const t = useTranslation();
  const [dragging, setDragging] = useState<string | null>(null);
  const [pending, setPending] = useState<string[] | null>(null);
  const [removing, setRemoving] = useState<T | null>(null);
  const order = useForm({});
  const removal = useForm({});
  const busy =
    disabled || pending !== null || order.processing || removal.processing;
  const rows = pending
    ? pending
        .map((id) => items.find((item) => item.id === id))
        .filter((item): item is T => !!item)
    : items;
  const move = (id: string, target: number) => {
    const from = rows.findIndex((item) => item.id === id);
    if (
      busy ||
      from < 0 ||
      target < 0 ||
      target >= rows.length ||
      from === target
    )
      return;
    const next = [...rows];
    next.splice(target, 0, next.splice(from, 1)[0]!);
    const ids = next.map((item) => item.id);
    setPending(ids);
    order.transform(() => orderData(ids));
    order.post(orderUrl, {
      preserveScroll: true,
      onStart: () => onBusyChange(true),
      onFinish: () => {
        setPending(null);
        onBusyChange(false);
      },
    });
  };
  return (
    <>
      <FormErrors errors={{ ...order.errors, ...removal.errors }} />
      <ul
        className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6"
        aria-label={label}
      >
        {rows.map((item, index) => {
          const title = name(item) || String(index + 1);
          return (
            <li
              key={item.id}
              draggable={!busy}
              className={
                'relative flex min-w-0 flex-col rounded-2xl border border-slate-200 bg-white p-3 shadow-sm ' +
                (dragging === item.id ? 'opacity-50' : '')
              }
              onDragStart={(event) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', item.id);
                setDragging(item.id);
              }}
              onDragEnd={() => setDragging(null)}
              onDragOver={(event) => {
                if (dragging !== null) {
                  event.preventDefault();
                  event.dataTransfer.dropEffect = 'move';
                }
              }}
              onDrop={(event) => {
                event.preventDefault();
                if (dragging !== null) move(dragging, index);
                setDragging(null);
              }}
            >
              <div className="mb-2 flex items-center justify-between text-xs text-slate-400">
                <button
                  type="button"
                  disabled={busy}
                  aria-label={t('company.payment_image_move', { name: title })}
                  title={t('company.payment_image_move', { name: title })}
                  className="cursor-grab rounded focus-visible:outline-2 focus-visible:outline-emerald-600"
                  onKeyDown={(event) => {
                    if (
                      [
                        'ArrowUp',
                        'ArrowDown',
                        'ArrowLeft',
                        'ArrowRight',
                      ].includes(event.key)
                    ) {
                      event.preventDefault();
                      move(
                        item.id,
                        index +
                          (['ArrowUp', 'ArrowLeft'].includes(event.key)
                            ? -1
                            : 1),
                      );
                    }
                  }}
                >
                  <DragGrip />
                </button>
                <span>{index + 1}</span>
              </div>
              {children(item)}
              <div className="mt-3 flex flex-1 items-end justify-between gap-2">
                <span className="min-w-0 text-sm font-semibold break-words text-slate-700">
                  {name(item) || '—'}
                </span>
                <span className="flex shrink-0">
                  {onEdit && (
                    <button
                      type="button"
                      disabled={busy}
                      className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-primary"
                      aria-label={t('common.edit') + ' ' + title}
                      title={t('common.edit')}
                      onClick={() => onEdit(item)}
                    >
                      <Icon name="edit" />
                    </button>
                  )}
                  <button
                    type="button"
                    disabled={busy}
                    className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                    aria-label={t('company.payment_image_remove', {
                      name: title,
                    })}
                    onClick={() => setRemoving(item)}
                  >
                    <Icon name="trash" />
                  </button>
                </span>
              </div>
            </li>
          );
        })}
        {after}
        {rows.length < limit && (
          <li>
            <button
              type="button"
              disabled={busy}
              onClick={onAdd}
              className="flex h-full min-h-44 w-full flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-slate-200 p-4 text-sm font-semibold text-primary hover:border-primary hover:bg-primary-tint/30"
            >
              <span className="rounded-full bg-primary-tint p-2">
                <Icon name="plus" />
              </span>
              {addLabel}
            </button>
          </li>
        )}
      </ul>
      {rows.length >= limit && (
        <p className="text-sm text-slate-500">{limitText}</p>
      )}
      {removing &&
        createPortal(
          <div onSubmit={(event) => event.stopPropagation()}>
            <ConfirmDeleteDialog
              title={removeTitle}
              subtitle={removeHint}
              facts={name(removing) ? [name(removing)] : []}
              confirmLabel={t('common.delete')}
              onCancel={() => setRemoving(null)}
              onConfirm={() => {
                if (removal.processing) return;
                removal.delete(removeUrl(removing), {
                  preserveScroll: true,
                  onStart: () => onBusyChange(true),
                  onFinish: () => onBusyChange(false),
                  onSuccess: () => setRemoving(null),
                });
              }}
            />
          </div>,
          document.body,
        )}
    </>
  );
}
