import { Fragment } from 'react';
import { usePage } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import type { CustomerGroupOption } from '../../types/customers';
import type { SharedProps } from '../../types/page';
import type { ProductSale } from '../../types/products';
import Select from './Select';
import DateInput from './DateInput';
import IconButton, { Icon } from './list/IconButton';

export default function ProductSalesFields({
  locale,
  rows,
  groups,
  currency,
  errors,
  onChange,
}: {
  locale?: string;
  rows: ProductSale[];
  groups: CustomerGroupOption[];
  currency: string;
  errors: Record<string, string>;
  onChange: (rows: ProductSale[]) => void;
}) {
  const t = useTranslation();
  const { locales } = usePage<SharedProps>().props.i18n;
  const set = (index: number, field: keyof ProductSale, value: string) =>
    onChange(
      rows.map((row, i) => (i === index ? { ...row, [field]: value } : row)),
    );
  const label = (field: string, index: number) =>
    t('sales.field_of', { field: t('sales.' + field), row: index + 1 });
  const input = (
    row: ProductSale,
    index: number,
    field:
      | 'minimum_quantity'
      | 'priority'
      | 'amount'
      | 'stock_limit'
      | 'starts_on'
      | 'ends_on'
      | 'starts_time'
      | 'ends_time',
    type = 'text',
  ) => (
    <div>
      {type === 'time' ? (
        <input
          className="field min-w-28"
          type="time"
          aria-label={label(field, index)}
          value={row[field]}
          aria-invalid={!!errors[`sales.${index}.${field}`] || undefined}
          onChange={(e) => set(index, field, e.target.value)}
        />
      ) : type === 'date' ? (
        <DateInput
          value={row[field]}
          aria-label={label(field, index)}
          className="field min-w-32"
          aria-invalid={!!errors[`sales.${index}.${field}`] || undefined}
          onChange={(value) => set(index, field, value)}
        />
      ) : (
        <input
          className="field min-w-28"
          type={type}
          aria-label={label(field, index)}
          inputMode={
            type === 'date'
              ? undefined
              : field === 'amount'
                ? 'decimal'
                : 'numeric'
          }
          placeholder={
            field === 'stock_limit' ? t('sales.all_stock') : undefined
          }
          value={row[field]}
          aria-invalid={!!errors[`sales.${index}.${field}`] || undefined}
          onChange={(e) => set(index, field, e.target.value)}
        />
      )}
      {errors[`sales.${index}.${field}`] && (
        <p role="alert" className="mt-1 text-sm text-red-700">
          {errors[`sales.${index}.${field}`]}
        </p>
      )}
    </div>
  );
  return (
    <div className="space-y-5">
      <div>
        <h3 className="text-base font-semibold">{t('sales.title')}</h3>
        <p className="mt-1 text-sm text-slate-500">
          {t('sales.intro', { currency })}
        </p>
      </div>
      <div className="relative max-w-full overflow-x-auto">
        <table className="data-table">
          <thead>
            <tr>
              {[
                'customer_group_id',
                'minimum_quantity',
                'priority',
                'kind',
                'amount',
                'stock_limit',
                'starts_on',
                'ends_on',
              ].map((field) => (
                <th key={field}>{t('sales.' + field)}</th>
              ))}
              <th>
                <span className="sr-only">{t('common.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <Fragment key={row.id || 'new-' + index}>
                <tr>
                  <td>
                    <Select
                      aria-label={label('customer_group_id', index)}
                      value={row.customer_group_id}
                      invalid={!!errors[`sales.${index}.customer_group_id`]}
                      onChange={(value) =>
                        set(index, 'customer_group_id', value)
                      }
                      options={[
                        { value: '', label: t('sales.all_customers') },
                        ...groups.map((g) => ({
                          value: g.id,
                          label: g.name,
                          disabled: !g.active,
                        })),
                      ]}
                    />
                    {errors[`sales.${index}.customer_group_id`] && (
                      <p role="alert" className="mt-1 text-sm text-red-700">
                        {errors[`sales.${index}.customer_group_id`]}
                      </p>
                    )}
                  </td>
                  <td>{input(row, index, 'minimum_quantity')}</td>
                  <td>{input(row, index, 'priority')}</td>
                  <td>
                    <Select
                      aria-label={label('kind', index)}
                      value={row.kind}
                      onChange={(value) => set(index, 'kind', value)}
                      options={[
                        {
                          value: 'fixed',
                          label: t('sales.fixed', { currency }),
                        },
                        { value: 'percentage', label: t('sales.percentage') },
                      ]}
                    />
                  </td>
                  <td>
                    {input(row, index, 'amount')}
                    <span className="mt-1 block text-xs text-slate-500">
                      {row.kind === 'percentage' ? '%' : currency}
                    </span>
                  </td>
                  <td>
                    {input(row, index, 'stock_limit')}
                    {row.used > 0 && (
                      <p className="mt-1 text-xs text-slate-500">
                        {t('sales.used', { count: row.used })}
                      </p>
                    )}
                  </td>
                  <td>
                    <div className="flex gap-2">
                      {input(row, index, 'starts_on', 'date')}
                      {input(row, index, 'starts_time', 'time')}
                    </div>
                  </td>
                  <td>
                    <div className="flex gap-2">
                      {input(row, index, 'ends_on', 'date')}
                      {input(row, index, 'ends_time', 'time')}
                    </div>
                  </td>
                  <td>
                    <IconButton
                      icon="trash"
                      label={t('sales.remove', { row: index + 1 })}
                      onClick={() =>
                        onChange(rows.filter((_, i) => i !== index))
                      }
                    />
                  </td>
                </tr>
                <tr>
                  <td colSpan={9}>
                    <fieldset className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                      <legend className="text-xs font-medium text-slate-500">
                        {t('sales.sale_title')} · {t('sales.sale_title_help')}
                      </legend>
                      {locales
                        .filter((l) => !locale || l.code === locale)
                        .map((l) => (
                          <div key={l.code}>
                            <input
                              className="field w-full"
                              maxLength={150}
                              aria-label={`${label('sale_title', index)} · ${l.code}`}
                              placeholder={l.code}
                              value={row.titles[l.code] ?? ''}
                              onChange={(e) =>
                                onChange(
                                  rows.map((r, i) =>
                                    i === index
                                      ? {
                                          ...r,
                                          titles: {
                                            ...r.titles,
                                            [l.code]: e.target.value,
                                          },
                                        }
                                      : r,
                                  ),
                                )
                              }
                            />
                            {errors[`sales.${index}.titles.${l.code}`] && (
                              <p
                                role="alert"
                                className="mt-1 text-sm text-red-700"
                              >
                                {errors[`sales.${index}.titles.${l.code}`]}
                              </p>
                            )}
                          </div>
                        ))}
                    </fieldset>
                  </td>
                </tr>
              </Fragment>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && (
          <p className="py-6 text-sm text-slate-500">{t('sales.empty')}</p>
        )}
      </div>
      <button
        type="button"
        className="button-secondary inline-flex items-center gap-2"
        disabled={rows.length >= 100}
        onClick={() =>
          onChange([
            ...rows,
            {
              id: '',
              customer_group_id: '',
              minimum_quantity: '1',
              priority: '1',
              kind: 'fixed',
              amount: '',
              stock_limit: '',
              starts_on: '',
              ends_on: '',
              starts_time: '',
              ends_time: '',
              titles: {},
              used: 0,
            },
          ])
        }
      >
        <Icon name="plus" />
        {t('sales.add')}
      </button>
      <p className="text-xs text-slate-500">{t('sales.help')}</p>
    </div>
  );
}
