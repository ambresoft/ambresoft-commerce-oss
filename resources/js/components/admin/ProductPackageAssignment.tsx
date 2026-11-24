import { Link, useForm } from '@inertiajs/react';
import { useId } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import FormErrors from './FormErrors';
import Card from './AdminCard';
import Select from './Select';
import { Icon } from './list/IconButton';

export type PackageAssignment = {
  id: string | null;
  options: { id: string; name: string }[];
  contents: { id: string; name: string }[];
};
export default function ProductPackageAssignment({
  product,
  value,
}: {
  product: string;
  value: PackageAssignment;
}) {
  const t = useTranslation();
  const id = useId();
  const form = useForm({ package: value.id ?? '', products: [product] });
  const changed = form.data.package !== (value.id ?? '');
  return (
    <Card
      id={id}
      icon="box"
      title={t('packages.title')}
      action={
        <Link className="button-secondary gap-2" href="/admin/product-packages">
          <Icon name="plus" className="size-4" />
          {t('packages.new')}
        </Link>
      }
    >
      <div className="space-y-5">
        <p className="text-sm leading-6 text-slate-500">
          {t('packages.product_assignment_help')}
        </p>
        <FormErrors errors={form.errors} />
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="min-w-0 flex-1 space-y-2">
            <label
              htmlFor={id + '-package'}
              className="block text-sm font-medium"
            >
              {t('packages.choose_package')}
            </label>
            <Select
              id={id + '-package'}
              aria-label={t('packages.choose_package')}
              value={form.data.package}
              disabled={form.processing}
              invalid={!!form.errors.package}
              onChange={(selected) => form.setData('package', selected)}
              options={[
                { value: '', label: t('packages.no_package') },
                ...value.options.map((p) => ({ value: p.id, label: p.name })),
              ]}
            />
          </div>
          <button
            type="button"
            className="button-primary shrink-0 gap-2"
            disabled={form.processing || !changed}
            onClick={() =>
              form.post('/admin/product-packages/assign', {
                preserveScroll: true,
              })
            }
          >
            <Icon name="check" className="size-4" />
            {t(
              form.processing
                ? 'common.wait'
                : form.data.package
                  ? 'packages.assign'
                  : 'packages.remove_assignment',
            )}
          </button>
        </div>
        {changed ? (
          <p className="text-sm text-slate-500">
            {t('packages.assignment_pending')}
          </p>
        ) : value.id ? (
          <div className="border-t border-slate-200 pt-4">
            <h3 className="mb-3 text-sm font-semibold">
              {t('packages.contents')}{' '}
              <span className="ml-1 text-slate-500">
                ({value.contents.length})
              </span>
            </h3>
            {value.contents.length ? (
              <ul className="grid gap-3 sm:grid-cols-2">
                {value.contents.map((p) => (
                  <li
                    key={p.id}
                    className="flex items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm"
                  >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-emerald-700">
                      <Icon name="box" className="size-4" />
                    </span>
                    <span className="min-w-0 break-words font-medium">
                      {p.name}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-500">
                {t('packages.contents_empty')}
              </p>
            )}
          </div>
        ) : (
          <p className="rounded-lg border border-dashed border-slate-200 p-4 text-sm text-slate-500">
            {t('packages.no_assignment')}
          </p>
        )}
      </div>
    </Card>
  );
}
