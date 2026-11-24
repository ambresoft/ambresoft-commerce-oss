import { useForm } from '@inertiajs/react';
import FormErrors from './FormErrors';
import { useTranslation } from '../../lib/useTranslation';
import { Icon, type IconName } from './list/IconButton';

export default function ConfirmedAction({
  url,
  label,
  confirmation,
  icon,
}: {
  url: string;
  label: string;
  confirmation: string;
  /** Render as a compact icon button (for table rows). */
  icon?: IconName;
}) {
  const form = useForm({});
  const t = useTranslation();
  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        if (window.confirm(confirmation)) form.delete(url);
      }}
    >
      <FormErrors errors={form.errors} />
      {icon ? (
        <button
          className="icon-button icon-button-danger"
          aria-label={label}
          title={label}
          disabled={form.processing}
        >
          <Icon name={icon} />
        </button>
      ) : (
        <button
          className="button-secondary text-red-700"
          disabled={form.processing}
        >
          {form.processing ? t('common.wait') : label}
        </button>
      )}
    </form>
  );
}
