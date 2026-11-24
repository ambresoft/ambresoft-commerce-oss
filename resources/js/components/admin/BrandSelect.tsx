import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Select from './Select';
import { useTranslation } from '../../lib/useTranslation';

/** One compact field, with bounded server search inside the shared dropdown. */
export default function BrandSelect({
  id,
  value,
  options,
  path,
  onChange,
  invalid = false,
}: {
  id: string;
  value: string;
  options: { id: string; name: string }[];
  path: string;
  onChange: (value: string) => void;
  invalid?: boolean;
}) {
  const t = useTranslation();
  const [query, setQuery] = useState<string | null>(null);
  useEffect(() => {
    if (query === null) return;
    const timer = window.setTimeout(
      () =>
        router.get(
          path,
          {
            kind: 'brands',
            search: query,
            selected: value ? [value] : [],
          },
          {
            only: ['options'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
          },
        ),
      250,
    );
    return () => window.clearTimeout(timer);
  }, [query, path, value]);
  return (
    <Select
      id={id}
      className="mt-2"
      aria-label={t('brands.brand')}
      invalid={invalid}
      value={value}
      onChange={onChange}
      search={{
        label: t('brands.search'),
        emptyMessage: t('common.no_results'),
        onChange: setQuery,
      }}
      options={[
        { value: '', label: t('brands.none') },
        ...options.map((brand) => ({ value: brand.id, label: brand.name })),
      ]}
    />
  );
}
