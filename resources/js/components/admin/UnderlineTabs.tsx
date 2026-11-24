import type { ReactNode } from 'react';
import AdminTabs, { AdminTab } from './AdminTabs';
import { useTranslation } from '../../lib/useTranslation';

export type UnderlineTab<T extends string> = {
  key: T;
  label: string;
  icon?: ReactNode;
  href?: string;
  error?: boolean;
};

/** Compatibility entry point for core and add-on screens using the shared admin tabs. */
export default function UnderlineTabs<T extends string>({
  tabs,
  active,
  label,
  onSelect,
  idPrefix = 'tab',
  language,
}: {
  tabs: UnderlineTab<T>[];
  active: T;
  label: string;
  onSelect?: (key: T) => void;
  idPrefix?: string;
  language?: ReactNode;
}) {
  const t = useTranslation();
  return (
    <AdminTabs label={label} language={language}>
      {tabs.map((tab) => (
        <AdminTab
          key={tab.key}
          id={`${idPrefix}-tab-${tab.key}`}
          href={tab.href}
          icon={tab.icon}
          iconKey={tab.key}
          aria-selected={active === tab.key}
          aria-controls={tab.href ? undefined : `${idPrefix}-panel-${tab.key}`}
          onClick={() => onSelect?.(tab.key)}
        >
          {tab.label}
          {tab.error && (
            <span className="text-red-600" aria-label={t('common.errors')}>
              ●
            </span>
          )}
        </AdminTab>
      ))}
    </AdminTabs>
  );
}
