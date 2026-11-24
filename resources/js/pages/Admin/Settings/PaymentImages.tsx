import { useMemo, useRef, useState } from 'react';
import {
  useIntake,
  usePreviews,
} from '../../../components/admin/ImageUploader';
import SortableImageTiles from '../../../components/admin/SortableImageTiles';
import { Icon } from '../../../components/admin/list/IconButton';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';

export type PaymentImage = {
  id: string;
  name: string;
  url: string;
  meta: { size: number; width: number; height: number };
};

const checker = {
  backgroundColor: '#fff',
  backgroundImage:
    'conic-gradient(#f1f3f2 25%, transparent 0 50%, #f1f3f2 0 75%, transparent 0)',
  backgroundSize: '12px 12px',
};

export default function PaymentImages({
  items,
  file,
  name,
  onFile,
  onName,
  onAdd,
  onClear,
  processing,
  progress,
  errors,
  onBusyChange,
}: {
  items: PaymentImage[];
  file: File | null;
  name: string;
  onFile: (file: File) => void;
  onName: (name: string) => void;
  onAdd: () => void;
  onClear: () => void;
  processing: boolean;
  progress: number | null;
  errors: { icon?: string; name?: string };
  onBusyChange: (busy: boolean) => void;
}) {
  const t = useTranslation();
  const input = useRef<HTMLInputElement>(null);
  const [adding, setAdding] = useState(false);
  const [tilesBusy, setTilesBusy] = useState(false);
  const { accept, messages } = useIntake(1);
  const files = useMemo(() => (file ? [file] : []), [file]);
  const [preview] = usePreviews(files);
  const busy = processing || tilesBusy;
  const draft = file ? { url: preview, name: name || file.name } : null;
  const close = () => {
    onClear();
    setAdding(false);
  };
  const choose = (incoming: File[]) => {
    const [next] = accept(incoming.slice(0, 1), 0);
    if (next) {
      onFile(next);
      setAdding(true);
    }
  };
  return (
    <div className="space-y-5">
      <header>
        <h2 className="flex items-center gap-2 text-base font-semibold">
          {t('company.payment_images_title')}
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
            {items.length + (draft ? 1 : 0)}
          </span>
        </h2>
        <p className="mt-2 text-sm text-slate-500">
          {t('company.payment_images_help')}
        </p>
      </header>
      <SortableImageTiles
        items={items}
        label={t('company.payment_images_title')}
        name={(item) => item.name}
        orderUrl="/admin/settings/company/payment-icons/order"
        removeUrl={(item) => '/admin/settings/company/payment-icons/' + item.id}
        removeTitle={t('company.payment_icon_remove')}
        removeHint={t('company.payment_image_remove_hint')}
        onAdd={() => setAdding(true)}
        addLabel={t('company.payment_image_add')}
        limit={12}
        limitText={t('company.payment_icons_limit', { count: 12 })}
        disabled={processing}
        onBusyChange={(value) => {
          setTilesBusy(value);
          onBusyChange(value);
        }}
        after={
          draft && (
            <li className="relative flex min-w-0 flex-col rounded-2xl border-2 border-dashed border-primary bg-primary-tint/30 p-3">
              <span className="absolute top-2 right-2 rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-on-primary">
                {t('media.new')}
              </span>
              <div
                className="mt-5 flex h-24 items-center justify-center rounded-xl p-4"
                style={checker}
              >
                <img
                  src={draft.url}
                  alt={draft.name}
                  className="max-h-full max-w-full object-contain"
                />
              </div>
              <div className="mt-3 flex items-end justify-between gap-2">
                <span className="min-w-0 text-sm font-semibold break-words text-slate-700">
                  {draft.name}
                </span>
                <button
                  type="button"
                  disabled={busy}
                  aria-label={t('media.remove_new', { name: draft.name })}
                  className="shrink-0 rounded p-1 text-red-600"
                  onClick={close}
                >
                  <Icon name="x" />
                </button>
              </div>
            </li>
          )
        }
      >
        {(item) => (
          <div
            className="flex h-24 items-center justify-center rounded-xl p-4"
            style={checker}
          >
            <img
              src={item.url}
              alt={item.name}
              className="max-h-full max-w-full object-contain"
              draggable={false}
            />
          </div>
        )}
      </SortableImageTiles>
      <input
        id="payment-image-file"
        ref={input}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        aria-label={t('company.payment_icon_file')}
        className="sr-only"
        tabIndex={-1}
        disabled={busy}
        onChange={(event) => {
          choose(Array.from(event.target.files ?? []));
          event.target.value = '';
        }}
      />
      {messages}
      {(adding || file) && (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
          <div className="mb-5 flex items-center justify-between">
            <h3 className="text-sm font-semibold">
              {t('company.payment_image_new')}
            </h3>
            <button
              type="button"
              className="button-secondary gap-1 px-3 py-1.5 text-xs"
              disabled={busy}
              onClick={close}
            >
              <Icon name="x" />
              {t('common.close')}
            </button>
          </div>
          <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <div className="space-y-2">
              <label
                htmlFor="payment-image-file"
                className="block text-sm font-medium text-slate-700"
              >
                {t('company.payment_icon_file')}
              </label>
              <div
                className="flex min-h-24 items-center gap-3 rounded-xl border border-slate-200 bg-white p-3"
                onDragOver={(event) => event.preventDefault()}
                onDrop={(event) => {
                  event.preventDefault();
                  if (!busy) choose(Array.from(event.dataTransfer.files));
                }}
              >
                <div
                  className="flex size-16 shrink-0 items-center justify-center rounded-lg p-2"
                  style={checker}
                >
                  {draft ? (
                    <img
                      src={draft.url}
                      alt=""
                      className="max-h-full max-w-full object-contain"
                    />
                  ) : (
                    <Icon name="upload" />
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold break-words">
                    {file?.name || t('media.choose_one')}
                  </p>
                  {file && (
                    <p className="mt-1 text-xs font-medium text-primary">
                      {Math.max(1, Math.round(file.size / 1024))} KB ·{' '}
                      {progress === null
                        ? t('company.payment_image_ready')
                        : `${progress}%`}
                    </p>
                  )}
                </div>
                <button
                  type="button"
                  disabled={busy}
                  className="button-secondary shrink-0 px-3 py-2 text-xs"
                  onClick={() => input.current?.click()}
                >
                  {t(
                    file ? 'company.payment_image_change' : 'media.choose_one',
                  )}
                </button>
              </div>
              <div className="flex flex-wrap gap-2 text-xs text-slate-500">
                {[
                  'PNG',
                  'JPEG',
                  'WebP',
                  t('company.payment_image_max_size'),
                ].map((label) => (
                  <span
                    key={label}
                    className="rounded-full border border-slate-200 bg-white px-2 py-0.5"
                  >
                    {label}
                  </span>
                ))}
              </div>
              <p className="text-xs leading-relaxed text-slate-500">
                {t('company.payment_image_hint')}
              </p>
              {errors.icon && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.icon}
                </p>
              )}
            </div>
            <div>
              <FormField
                id="payment-icon-name"
                label={t('company.payment_image_name')}
                hint={t('company.payment_image_name_hint')}
                value={name}
                maxLength={80}
                error={errors.name}
                disabled={busy}
                onChange={(event) => onName(event.target.value)}
              />
              <div className="mt-4 flex gap-2">
                <button
                  type="button"
                  className="button-primary px-4 py-2 text-sm"
                  disabled={busy || !file || items.length >= 12}
                  onClick={onAdd}
                >
                  {t('company.payment_image_add_list')}
                </button>
                <button
                  type="button"
                  className="button-secondary px-4 py-2 text-sm"
                  disabled={busy}
                  onClick={close}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-4 rounded-2xl bg-[#1c2924] px-5 py-4 text-white">
        <span className="text-xs text-white/60">
          {t('company.payment_footer_preview')}
        </span>
        <div className="flex flex-wrap items-center gap-2">
          {[...items, ...(draft ? [{ id: 'draft', ...draft }] : [])].map(
            (item) => (
              <span
                key={item.id}
                className="flex h-8 w-12 items-center justify-center rounded-md bg-white p-1 shadow-sm"
              >
                <img
                  src={item.url}
                  alt={item.name}
                  className="max-h-full max-w-full object-contain"
                />
              </span>
            ),
          )}
        </div>
        <span className="text-xs text-white/50 sm:ml-auto">
          ⓘ {t('company.payment_footer_order')}
        </span>
      </div>
    </div>
  );
}
