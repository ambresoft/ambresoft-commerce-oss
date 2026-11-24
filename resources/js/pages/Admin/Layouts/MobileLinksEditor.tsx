import { useState } from 'react';
import FormField from '../../../components/auth/FormField';
import DragGrip from '../../../components/admin/list/DragGrip';
import IconButton from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { MobileMenuLinks } from '../../../types/layouts';

export default function MobileLinksEditor({
  locale,
  value,
  enabled,
  onEnabled,
  onChange,
}: {
  languages: { code: string; name: string }[];
  locale: string;
  onLocale: (code: string) => void;
  value: MobileMenuLinks;
  enabled: boolean;
  onEnabled: (enabled: boolean) => void;
  onChange: (value: MobileMenuLinks) => void;
}) {
  const t = useTranslation();
  const [dragging, setDragging] = useState<number | null>(null);
  const move = (from: number, to: number) => {
    if (to < 0 || to >= value.items.length) return;
    const items = [...value.items];
    items.splice(to, 0, items.splice(from, 1)[0]);
    onChange({ ...value, items });
    window.setTimeout(
      () =>
        document.getElementById(`mobile-link-grip-${locale}-${to}`)?.focus(),
      0,
    );
  };
  return (
    <div className="space-y-5">
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={enabled}
          onChange={(e) => onEnabled(e.target.checked)}
        />
        {t('layouts.show_mobile_account_links')}
      </label>
      <p className="text-sm text-slate-500">{t('layouts.mobile_links_help')}</p>
      <FormField
        id="mobile-links-title"
        label={t('layouts.mobile_links_title')}
        maxLength={100}
        value={value.title}
        onChange={(e) => onChange({ ...value, title: e.target.value })}
      />
      <ol className="space-y-3">
        {value.items.map((item, index) => (
          <li
            key={`${locale}-${index}`}
            draggable
            onDragStart={(e) => {
              e.dataTransfer.effectAllowed = 'move';
              e.dataTransfer.setData('text/plain', String(index));
              setDragging(index);
            }}
            onDragOver={(e) => {
              if (dragging !== null) e.preventDefault();
            }}
            onDragEnd={() => setDragging(null)}
            onDrop={(e) => {
              e.preventDefault();
              if (dragging !== null) move(dragging, index);
              setDragging(null);
            }}
            className="flex items-start gap-3 rounded-lg border border-slate-200 p-3"
          >
            <button
              id={`mobile-link-grip-${locale}-${index}`}
              type="button"
              className="mt-7 cursor-grab text-slate-400"
              aria-label={t('layouts.mobile_link_move', { number: index + 1 })}
              onKeyDown={(e) => {
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                  e.preventDefault();
                  move(index, index + (e.key === 'ArrowUp' ? -1 : 1));
                }
              }}
            >
              <DragGrip />
            </button>
            <div className="min-w-0 flex-1 space-y-3">
              <FormField
                id={`mobile-link-label-${index}`}
                label={t('layouts.mobile_link_label', { number: index + 1 })}
                maxLength={100}
                required
                value={item.label}
                onChange={(e) =>
                  onChange({
                    ...value,
                    items: value.items.map((row, i) =>
                      i === index ? { ...row, label: e.target.value } : row,
                    ),
                  })
                }
              />
              <FormField
                id={`mobile-link-url-${index}`}
                label={t('layouts.mobile_link_url', { number: index + 1 })}
                maxLength={2048}
                required
                value={item.url}
                onChange={(e) =>
                  onChange({
                    ...value,
                    items: value.items.map((row, i) =>
                      i === index ? { ...row, url: e.target.value } : row,
                    ),
                  })
                }
              />
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={item.new_window}
                  onChange={(e) =>
                    onChange({
                      ...value,
                      items: value.items.map((row, i) =>
                        i === index
                          ? { ...row, new_window: e.target.checked }
                          : row,
                      ),
                    })
                  }
                />
                {t('layouts.mobile_link_new_window')}
              </label>
            </div>
            <IconButton
              icon="trash"
              danger
              label={t('layouts.mobile_link_remove', { number: index + 1 })}
              onClick={() =>
                onChange({
                  ...value,
                  items: value.items.filter((_, i) => i !== index),
                })
              }
            />
          </li>
        ))}
      </ol>
      <button
        type="button"
        className="button-secondary"
        disabled={value.items.length >= 50}
        onClick={() =>
          onChange({
            ...value,
            items: [...value.items, { label: '', url: '', new_window: false }],
          })
        }
      >
        {t('layouts.mobile_link_add')}
      </button>
    </div>
  );
}
