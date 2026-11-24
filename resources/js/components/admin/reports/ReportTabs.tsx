import AdminTabs, { AdminTab } from '../AdminTabs';
import { useReportUrl } from '../../../lib/reportQuery';

/** Switches report views while keeping every filter. */
export default function ReportTabs({
  label,
  param = 'view',
  current,
  items,
}: {
  label: string;
  param?: string;
  current: string;
  items: { value: string; label: string }[];
}) {
  const { href } = useReportUrl();
  return (
    <AdminTabs label={label}>
      {items.map((item) => (
        <AdminTab
          key={item.value}
          href={href({ [param]: item.value })}
          preserveScroll
          aria-selected={current === item.value}
          iconKey={item.value}
        >
          {item.label}
        </AdminTab>
      ))}
    </AdminTabs>
  );
}
