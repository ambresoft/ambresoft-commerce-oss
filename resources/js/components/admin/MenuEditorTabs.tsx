import AdminTabs, { AdminTab } from './AdminTabs';
import type { ReactNode } from 'react';
import { useTranslation } from '../../lib/useTranslation';

export default function MenuEditorTabs({
  tabs,
  active,
  onChange,
  errors,
  language,
}: {
  tabs: readonly string[];
  active: string;
  onChange: (tab: string) => void;
  errors: Record<string, string>;
  language?: ReactNode;
}) {
  const t = useTranslation();
  return (
    <>
      <AdminTabs label={t('menus.edit')} language={language}>
        {tabs.map((tab) => (
          <AdminTab
            key={tab}
            id={'menu-tab-' + tab}
            role="tab"
            type="button"
            aria-selected={active === tab}
            aria-controls={'menu-panel-' + tab}
            tabIndex={active === tab ? 0 : -1}
            onClick={() => onChange(tab)}
            iconKey={tab}
          >
            {t(`menus.tab_${tab}`)}
            {Object.keys(errors).some((key) =>
              tab === 'appearance'
                ? /image|description/.test(key)
                : !/image|description/.test(key),
            ) && (
              <span
                aria-label={t('common.errors')}
                className="ml-2 text-red-600"
              >
                ●
              </span>
            )}
          </AdminTab>
        ))}
      </AdminTabs>
    </>
  );
}
