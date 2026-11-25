import { usePage } from '@inertiajs/react';
import CategoryDeleteAction from './CategoryDeleteAction';
import IconButton from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';
import type { CategorySummary } from '../../types/categories';
import type { SharedProps } from '../../types/page';

export default function CategoryActions({
  category,
}: {
  category: CategorySummary;
}) {
  const { capabilities } = usePage<SharedProps>().props;
  const t = useTranslation();
  if (category.system) return null;
  return (
    <div className="flex flex-wrap items-start justify-end gap-2">
      {capabilities['categories.update'] && (
        <IconButton
          icon="edit"
          href={category.editUrl}
          label={t('common.edit') + ': ' + category.name}
        />
      )}
      {capabilities['categories.create'] && (
        <IconButton
          icon="plus"
          href={category.childUrl}
          label={t('categories.add_child') + ': ' + category.name}
        />
      )}
      {capabilities['categories.delete'] && category.childrenCount === 0 && (
        <CategoryDeleteAction
          icon
          id={category.id}
          name={category.name}
          version={category.version}
          childrenCount={category.childrenCount}
        />
      )}
    </div>
  );
}
