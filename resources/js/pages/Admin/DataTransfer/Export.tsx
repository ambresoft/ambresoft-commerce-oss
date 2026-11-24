import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import Select from '../../../components/admin/Select';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';

type Props = AdminShell & {
  entity: string;
  title: string;
  backHref: string;
  columns: string[];
  delimiters: string[];
  scope: { filters: Record<string, string | number>; ids: string[] };
};

export default function DataTransferExport({
  entity,
  title,
  backHref,
  columns,
  delimiters,
  scope,
  ...shell
}: Props) {
  const t = useTranslation();
  const [selected, setSelected] = useState<string[]>(columns);
  const [delimiter, setDelimiter] = useState(delimiters[0] ?? ',');
  const heading = t('admin.data_transfer.export_title', { entity: title });
  const filtered = Object.keys(scope.filters).length > 0;
  const scopeText = scope.ids.length
    ? t('admin.data_transfer.scope_selected', { count: scope.ids.length })
    : filtered
      ? t('admin.data_transfer.scope_filtered')
      : t('admin.data_transfer.scope_all');

  return (
    <AdminLayout title={heading} {...shell}>
      <EditorHeader
        heading={heading}
        iconKey="core.data-transfer"
        backHref={backHref}
        description={
          <p className="mt-2 max-w-2xl text-sm text-slate-500">
            {t('admin.data_transfer.export_intro')}
          </p>
        }
      />
      <form
        method="get"
        action={`/admin/data-transfer/export/${entity}/download`}
      >
        {Object.entries(scope.filters).map(([name, value]) => (
          <input key={name} type="hidden" name={name} value={value} />
        ))}
        {scope.ids.map((id) => (
          <input key={id} type="hidden" name="ids[]" value={id} />
        ))}
        <input type="hidden" name="delimiter" value={delimiter} />
        <AdminCard
          id="export-fields"
          title={t('admin.data_transfer.fields')}
          iconKey="core.data-transfer"
          action={
            <div className="flex gap-2">
              <button
                type="button"
                className="button-secondary"
                onClick={() => setSelected(columns)}
              >
                {t('admin.data_transfer.select_all')}
              </button>
              <button
                type="button"
                className="button-secondary"
                onClick={() => setSelected([])}
              >
                {t('admin.data_transfer.deselect_all')}
              </button>
            </div>
          }
        >
          <p className="mb-4 text-sm text-slate-500">{scopeText}</p>
          <ul className="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
            {columns.map((column) => (
              <li key={column}>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-4 shrink-0"
                    name="columns[]"
                    value={column}
                    checked={selected.includes(column)}
                    onChange={(event) =>
                      setSelected(
                        event.target.checked
                          ? [...selected, column]
                          : selected.filter((item) => item !== column),
                      )
                    }
                  />
                  <code className="break-all">{column}</code>
                </label>
              </li>
            ))}
          </ul>
          <div className="mt-6 flex flex-wrap items-end justify-between gap-4 border-t border-slate-100 pt-4">
            <div className="w-56">
              <label
                htmlFor="export-delimiter"
                className="mb-2 block text-sm font-medium"
              >
                {t('admin.data_transfer.delimiter')}
              </label>
              <Select
                id="export-delimiter"
                value={delimiter}
                onChange={setDelimiter}
                options={delimiters.map((value) => ({
                  value,
                  label:
                    value === ','
                      ? t('admin.data_transfer.comma')
                      : t('admin.data_transfer.semicolon'),
                }))}
              />
            </div>
            <div className="flex items-center gap-4">
              <span className="text-sm text-slate-500" aria-live="polite">
                {selected.length
                  ? t('admin.data_transfer.fields_selected', {
                      count: selected.length,
                      total: columns.length,
                    })
                  : t('admin.data_transfer.pick_one')}
              </span>
              <button
                type="submit"
                className="button-primary"
                disabled={selected.length === 0}
              >
                {t('admin.data_transfer.download')}
              </button>
            </div>
          </div>
        </AdminCard>
      </form>
    </AdminLayout>
  );
}
