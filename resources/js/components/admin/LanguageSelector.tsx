import { useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '../../types/page';
import { useTranslation } from '../../lib/useTranslation';
import Dropdown, { Chevron } from './Dropdown';

export default function LanguageSelector() {
  const { i18n } = usePage<SharedProps>().props;
  const t = useTranslation();
  const form = useForm({
    locale: i18n.locale,
    return_to: window.location.pathname + window.location.search,
  });
  const current =
    i18n.locales.find((locale) => locale.code === i18n.locale)?.name ??
    i18n.locale;
  return (
    <div className="shrink-0">
      <Dropdown
        label={`${t('common.language')}: ${current}`}
        triggerClassName="admin-header-control h-8 gap-1.5 px-1.5 text-sm font-medium disabled:opacity-60"
        panelClassName="w-48 p-1.5"
        trigger={
          <>
            <span className="hidden sm:inline">{current}</span>
            <span className="sm:hidden" aria-hidden="true">
              {i18n.locale.toUpperCase()}
            </span>
            <Chevron />
          </>
        }
      >
        {(close) => (
          <ul>
            {i18n.locales.map((locale) => {
              const selected = locale.code === i18n.locale;
              return (
                <li key={locale.code}>
                  <button
                    type="button"
                    lang={locale.code}
                    aria-current={selected ? 'true' : undefined}
                    disabled={form.processing}
                    className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-slate-50 ${selected ? 'font-semibold text-emerald-900' : 'text-slate-700'}`}
                    onClick={() => {
                      close();
                      if (selected) return;
                      form.transform(() => ({
                        locale: locale.code,
                        return_to:
                          window.location.pathname + window.location.search,
                      }));
                      form.patch('/admin/locale', { preserveScroll: true });
                    }}
                  >
                    {locale.name}
                    {selected && (
                      <svg
                        aria-hidden="true"
                        viewBox="0 0 20 20"
                        fill="none"
                        className="size-4 text-primary"
                      >
                        <path
                          d="m4.5 10.5 3.5 3.5 7.5-8"
                          stroke="currentColor"
                          strokeWidth="1.75"
                        />
                      </svg>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </Dropdown>
      {form.errors.locale && (
        <p role="alert" className="text-xs text-red-700">
          {form.errors.locale}
        </p>
      )}
    </div>
  );
}
