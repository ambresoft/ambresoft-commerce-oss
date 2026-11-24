import { useId, type ReactNode } from 'react';
import { Icon, type IconName } from './list/IconButton';
import TabIcon from './TabIcon';

export default function AdminCard({
  id,
  title,
  action,
  icon = 'settings',
  iconKey,
  bodyClassName = '',
  count,
  children,
}: {
  id?: string;
  title: ReactNode;
  action?: ReactNode;
  icon?: IconName | null;
  iconKey?: string;
  bodyClassName?: string;
  count?: number;
  children: ReactNode;
}) {
  const generatedId = useId();
  const headingId = id ?? generatedId;
  return (
    <section
      aria-labelledby={headingId}
      className="rounded-lg border border-slate-200 bg-white"
    >
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
        <div className="flex items-center gap-2.5">
          {icon !== null && (
            <span
              aria-hidden="true"
              className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-tint p-1.5 text-emerald-700"
            >
              {iconKey ? <TabIcon name={iconKey} /> : <Icon name={icon} />}
            </span>
          )}
          <h2 id={headingId} className="text-base font-semibold">
            {title}
          </h2>
          {count !== undefined && (
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
              {count}
            </span>
          )}
        </div>
        {action}
      </div>
      <div className={bodyClassName || 'p-5'}>{children}</div>
    </section>
  );
}
