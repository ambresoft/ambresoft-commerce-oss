import { useTranslation } from '../../lib/useTranslation';

export default function FormErrors({
  errors,
}: {
  errors: Record<string, string>;
}) {
  const t = useTranslation();
  const entries = Object.entries(errors).filter(([, message]) => !!message);
  if (!entries.length) return null;
  return (
    <div
      role="alert"
      className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      <p className="font-medium">{t('common.errors')}</p>
      <ul className="mt-2 list-inside list-disc">
        {entries.map(([key, message]) => (
          <li key={key}>{message}</li>
        ))}
      </ul>
    </div>
  );
}
