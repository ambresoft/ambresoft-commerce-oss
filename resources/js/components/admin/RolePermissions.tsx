import AdminTabs, { AdminTab } from './AdminTabs';
import { useId, useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';

export type RolePermission = { name: string; label: string };

// Presentation groups only: grantability and saved permissions remain server-owned.
const groups = [
  {
    key: 'catalog',
    label: 'roles.permission_group_catalog',
    prefixes: [
      'products',
      'categories',
      'features',
      'variations',
      'brands',
      'availability-statuses',
      'media',
      'reviews',
    ],
  },
  {
    key: 'orders',
    label: 'roles.permission_group_orders',
    prefixes: [
      'orders',
      'order-statuses',
      'order-emails',
      'call-requests',
      'carts',
    ],
  },
  {
    key: 'customers',
    label: 'roles.permission_group_customers',
    prefixes: ['customers', 'customer-groups', 'customer-blacklist'],
  },
  {
    key: 'website',
    label: 'roles.permission_group_website',
    prefixes: [
      'layouts',
      'menus',
      'pages',
      'faqs',
      'theme-code',
      'social-login',
    ],
  },
  {
    key: 'marketing',
    label: 'roles.permission_group_marketing',
    prefixes: [
      'banners',
      'promotions',
      'coupons',
      'giftcards',
      'newsletters',
      'subscribers',
      'stickers',
    ],
  },
  {
    key: 'shipping',
    label: 'roles.permission_group_shipping',
    prefixes: [
      'payment-methods',
      'shipping-methods',
      'shipping-carriers',
      'taxes',
      'rate-areas',
      'countries',
      'regions',
    ],
  },
  {
    key: 'settings',
    label: 'roles.permission_group_settings',
    prefixes: [
      'settings',
      'currencies',
      'languages',
      'search',
      'ai',
      'reports',
      'audit',
    ],
  },
  {
    key: 'access',
    label: 'roles.permission_group_access',
    prefixes: ['admin', 'users', 'roles'],
  },
  { key: 'other', label: 'roles.permission_group_other', prefixes: [] },
];
const groupFor = (name: string) =>
  groups.find((group) => group.prefixes.includes(name.split('.')[0]))?.key ??
  'other';
const groupLabel = (key: string) =>
  groups.find((group) => group.key === key)?.label ??
  'roles.permission_group_all';
const normalize = (text: string) =>
  text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase();

export default function RolePermissions({
  permissions,
  selected,
  onChange,
  disabled = false,
}: {
  permissions: RolePermission[];
  selected: string[];
  onChange: (permissions: string[]) => void;
  disabled?: boolean;
}) {
  const t = useTranslation();
  const id = useId();
  const available = groups
    .map((group) => ({
      ...group,
      items: permissions.filter(
        (permission) => groupFor(permission.name) === group.key,
      ),
    }))
    .filter((group) => group.items.length > 0);
  const [category, setCategory] = useState(available[0]?.key ?? 'all');
  const [search, setSearch] = useState('');
  const tabs = [
    { key: 'all', label: 'roles.permission_group_all', items: permissions },
    ...available,
  ];
  const active = tabs.some((group) => group.key === category)
    ? category
    : tabs[0].key;
  const query = normalize(search.trim());
  const matches = (permission: RolePermission) =>
    normalize(
      `${permission.label} ${permission.name} ${t(groupLabel(groupFor(permission.name)))}`,
    ).includes(query);
  const shown = available
    .filter((group) => active === 'all' || group.key === active)
    .map((group) => ({
      ...group,
      items: group.items
        .filter(matches)
        .sort((a, b) => a.name.localeCompare(b.name)),
    }))
    .filter((group) => group.items.length > 0);
  const count = shown.reduce((sum, group) => sum + group.items.length, 0);
  const selectedCount = permissions.filter((permission) =>
    selected.includes(permission.name),
  ).length;
  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="w-full sm:max-w-md">
          <label htmlFor={`${id}-search`} className="sr-only">
            {t('roles.permission_search')}
          </label>
          <input
            id={`${id}-search`}
            type="search"
            className="field w-full"
            placeholder={t('roles.permission_search')}
            value={search}
            onKeyDown={(event) => {
              if (event.key === 'Enter') event.preventDefault();
            }}
            onChange={(event) => {
              setSearch(event.target.value);
              setCategory('all');
            }}
          />
        </div>
        <p className="text-sm text-slate-500" role="status">
          {t('roles.permission_summary', {
            shown: count,
            total: permissions.length,
            selected: selectedCount,
          })}
        </p>
      </div>
      <AdminTabs label={t('roles.permission_groups')} className="mb-6">
        {tabs.map((group) => {
          const label = t(group.label);
          const assigned = group.items.filter((permission) =>
            selected.includes(permission.name),
          ).length;
          return (
            <AdminTab
              key={group.key}
              id={`${id}-tab-${group.key}`}
              type="button"
              role="tab"
              aria-label={label}
              aria-selected={active === group.key}
              aria-controls={`${id}-panel`}
              tabIndex={active === group.key ? 0 : -1}
              onClick={() => setCategory(group.key)}
              iconKey={group.key}
            >
              {label}
              <span
                aria-hidden="true"
                className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500"
                title={t('roles.permission_group_count', {
                  selected: assigned,
                  total: group.items.length,
                })}
              >
                {assigned}/{group.items.length}
              </span>
            </AdminTab>
          );
        })}
      </AdminTabs>
      <div
        role="tabpanel"
        id={`${id}-panel`}
        aria-labelledby={`${id}-tab-${active}`}
      >
        {shown.length === 0 ? (
          <div className="rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-500">
            <p>{t('roles.permission_no_results')}</p>
            {search && (
              <button
                type="button"
                className="mt-3 text-link"
                onClick={() => setSearch('')}
              >
                {t('roles.permission_clear_search')}
              </button>
            )}
          </div>
        ) : (
          <div className="space-y-6">
            {shown.map((group) => (
              <fieldset key={group.key}>
                <legend className="mb-3 text-base font-semibold">
                  {t(group.label)}
                </legend>
                <div className="grid gap-3 sm:grid-cols-2">
                  {group.items.map((permission) => (
                    <label
                      key={permission.name}
                      className={`flex items-start gap-3 rounded-lg border p-3 text-sm ${selected.includes(permission.name) ? 'border-primary/30 bg-primary-tint/40' : 'border-slate-200'} ${disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-primary/40'}`}
                    >
                      <input
                        type="checkbox"
                        className="mt-0.5 size-4 shrink-0"
                        disabled={disabled}
                        checked={selected.includes(permission.name)}
                        onChange={(event) =>
                          onChange(
                            event.target.checked
                              ? [...selected, permission.name]
                              : selected.filter(
                                  (name) => name !== permission.name,
                                ),
                          )
                        }
                      />
                      <span className="min-w-0">
                        {permission.label}
                        <code className="mt-1 block text-xs break-all text-slate-500">
                          {permission.name}
                        </code>
                      </span>
                    </label>
                  ))}
                </div>
              </fieldset>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
