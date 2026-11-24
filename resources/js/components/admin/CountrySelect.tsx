import type { ComponentProps } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import Select from './Select';

/** Country selectors always expose the shared localized name/code search. */
export default function CountrySelect(
  props: Omit<ComponentProps<typeof Select>, 'search'>,
) {
  const t = useTranslation();
  return (
    <Select
      {...props}
      search={{
        label: t('common.search_options'),
        emptyMessage: t('common.no_options'),
      }}
    />
  );
}
