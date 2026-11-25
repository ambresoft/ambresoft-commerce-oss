import { useTranslation } from '../../lib/useTranslation';
import type { ReactNode } from 'react';

export default function SubmitButton({
  processing,
  children,
}: {
  processing: boolean;
  children: ReactNode;
}) {
  const t = useTranslation();
  return (
    <button
      type="submit"
      disabled={processing}
      className="button-primary w-full"
      aria-busy={processing}
    >
      {processing ? t('common.wait') : children}
    </button>
  );
}
