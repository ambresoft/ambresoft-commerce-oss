import { useForm } from '@inertiajs/react';
import FormErrors from './FormErrors';
import { useTranslation } from '../../lib/useTranslation';
import { Icon } from './list/IconButton';

export default function CategoryDeleteAction({
  id,
  name,
  version,
  childrenCount,
  labelKey = 'common.delete',
  className = 'rounded text-red-700 hover:underline disabled:opacity-50',
  icon = false,
}: {
  id: string;
  name: string;
  version: number;
  childrenCount: number;
  labelKey?: string;
  className?: string;
  /** Compact trash icon button for list rows. */
  icon?: boolean;
}) {
  const t = useTranslation();
  const form = useForm({ version });

  return (
    <form
      className="space-y-2"
      onSubmit={(event) => {
        event.preventDefault();
        if (window.confirm(t('categories.confirm_delete', { name }))) {
          form.delete('/admin/categories/' + id, { preserveState: 'errors' });
        }
      }}
    >
      {icon ? (
        <button
          type="submit"
          className="icon-button icon-button-danger"
          aria-label={t(labelKey) + ': ' + name}
          title={t(labelKey)}
          disabled={form.processing || childrenCount > 0}
        >
          <Icon name="trash" />
        </button>
      ) : (
        <button
          type="submit"
          className={className}
          disabled={form.processing || childrenCount > 0}
        >
          {t(form.processing ? 'common.wait' : labelKey)}
        </button>
      )}
      {childrenCount > 0 && (
        <p className="max-w-64 text-xs text-slate-600">
          {t('categories.errors.children')}
        </p>
      )}
      <FormErrors errors={form.errors} />
    </form>
  );
}
