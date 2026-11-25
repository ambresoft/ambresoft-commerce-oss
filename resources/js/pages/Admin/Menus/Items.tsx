import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import IconButton from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { Menu, MenuItem, ItemRow } from '../../../types/menus';
export default function Items({
  menu,
  name,
  parent,
  generated,
  generatedFrom,
  categoryParent,
  items,
  ...shell
}: AdminShell & {
  menu: Menu;
  name: string;
  parent: MenuItem | null;
  generated: boolean;
  generatedFrom: string | null;
  categoryParent: string | null;
  items: Page<ItemRow>;
}) {
  const t = useTranslation();
  const { capabilities, errors = {} } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const allowed = !!capabilities['menus.manage'];
  const base = '/admin/menus/' + menu.id;
  const categoryHref = (id: string) =>
    base +
    '?category_parent=' +
    id +
    (generatedFrom ? '&generated_from=' + generatedFrom : '');
  const remove = (row: ItemRow) => {
    if (window.confirm(t('menus.delete_item_confirm')))
      router.delete(base + '/items/' + row.id, {
        data: { version: menu.version, confirmed: true },
        preserveScroll: true,
      });
  };
  return (
    <AdminLayout title={name} {...shell}>
      <EditorHeader
        heading={name}
        iconKey="features"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">{t('menus.intro')}</p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-3">
              <Link className="button-secondary" href={base + '/edit'}>
                {t('menus.settings')}
              </Link>
              {allowed && !generated && (
                <Link
                  className="button-primary"
                  href={
                    base +
                    '/items/create' +
                    (parent ? '?parent=' + parent.id : '')
                  }
                >
                  <Icon name="plus" className="size-5 shrink-0" />
                  {t('menus.add_item')}
                </Link>
              )}
            </div>
          </>
        }
      />
      {parent && (
        <p className="mb-4 text-sm font-medium text-slate-700">
          {t('menus.parent')}: {parent.name ?? '#' + parent.id}
        </p>
      )}
      <FormErrors errors={errors} />
      <div className="mb-4 flex flex-wrap gap-3">
        <Link className="button-secondary" href="/admin/menus">
          {t('menus.back')}
        </Link>
        {(parent || categoryParent) && (
          <Link className="button-secondary" href={base}>
            {t('menus.root')}
          </Link>
        )}
        {parent && !generated && parent.parent_id && (
          <Link
            className="button-secondary"
            href={base + '?parent=' + parent.parent_id}
          >
            {t('menus.parent')}
          </Link>
        )}
      </div>
      {generated && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-primary-tint p-4 text-sm text-emerald-900">
          {t('menus.generated_help')}
        </p>
      )}
      <div className="list-card">
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'position',
                  'name',
                  'target',
                  'children',
                  'status',
                  'actions',
                ].map((k) => (
                  <th key={k}>
                    {t(
                      ['name', 'status', 'actions'].includes(k)
                        ? 'common.' + k
                        : 'menus.' + k,
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.data.map((row) => (
                <tr key={row.id}>
                  <td className="tabular-nums">{row.position}</td>
                  <td>
                    {generated ? (
                      <span className="font-medium">{row.name}</span>
                    ) : (
                      <Link
                        className="font-medium text-primary hover:underline"
                        href={base + '/items/' + row.id + '/edit'}
                      >
                        {row.name}
                      </Link>
                    )}
                  </td>
                  <td className="max-w-64 break-all text-sm">
                    {generated
                      ? t('menus.category') + ' #' + row.id
                      : row.kind === 'url'
                        ? row.url
                        : row.kind === 'category'
                          ? t('menus.category') + ' #' + row.category_id
                          : t('menus.heading')}
                  </td>
                  <td>
                    {row.children_count > 0 || row.generate_children ? (
                      <Link
                        className="button-secondary"
                        href={
                          generated
                            ? categoryHref(row.id)
                            : base + '?parent=' + row.id
                        }
                      >
                        {row.generate_children
                          ? t('menus.generated_submenu')
                          : t('menus.children_count', {
                              count: row.children_count,
                            })}
                      </Link>
                    ) : (
                      0
                    )}
                  </td>
                  <td>
                    <StatusBadge
                      tone={row.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t('menus.' + row.status)}
                    </StatusBadge>
                  </td>
                  <td>
                    {!generated && (
                      <div className="flex items-center gap-1">
                        <IconButton
                          icon="edit"
                          label={t('common.edit')}
                          href={base + '/items/' + row.id + '/edit'}
                        />
                        {allowed && !row.generate_children && (
                          <IconButton
                            icon="plus"
                            label={t('menus.add_child')}
                            href={base + '/items/create?parent=' + row.id}
                          />
                        )}
                        {allowed && (
                          <IconButton
                            icon="trash"
                            label={t('common.delete')}
                            danger
                            onClick={() => remove(row)}
                          />
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {items.data.length === 0 && (
          <p className="p-8 text-center text-sm text-slate-500">
            {t('common.empty')}
          </p>
        )}
        <Pagination page={items} />
      </div>
    </AdminLayout>
  );
}
