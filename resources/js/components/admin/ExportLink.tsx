import { Link } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import { Icon } from './list/IconButton';

/**
 * Opens the shared export field picker. Applied list filters and explicitly
 * selected rows travel in the query string; with neither, every record is exported.
 */
export default function ExportLink({
  entity,
  filters = {},
  ids = [],
}: {
  entity: string;
  filters?: Record<string, string | number | undefined | null>;
  ids?: string[];
}) {
  const t = useTranslation();
  const query = new URLSearchParams();
  for (const [name, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') {
      query.append(name, String(value));
    }
  }
  ids.forEach((id) => query.append('ids[]', id));
  const search = query.toString();
  return (
    <Link
      href={`/admin/data-transfer/export/${entity}${search ? '?' + search : ''}`}
      className="button-secondary gap-2"
    >
      <Icon name="download" />
      {t('admin.data_transfer.export_action')}
    </Link>
  );
}
