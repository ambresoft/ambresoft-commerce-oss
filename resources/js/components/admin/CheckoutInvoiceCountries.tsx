import { useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import { SearchField } from './list/FilterBar';
import StatusBadge from './list/StatusBadge';
import Switch from './Switch';

export default function CheckoutInvoiceCountries({
  countries,
  selected,
  covered,
  onChange,
}: {
  countries: { code: string; name: string }[];
  selected: string[];
  covered: string[];
  onChange: (codes: string[]) => void;
}) {
  const t = useTranslation();
  const [search, setSearch] = useState('');
  const query = search.trim().toLocaleLowerCase();
  const rows = countries
    .filter((country) =>
      `${country.name} ${country.code}`.toLocaleLowerCase().includes(query),
    )
    .sort(
      (a, b) =>
        Number(selected.includes(b.code)) - Number(selected.includes(a.code)),
    );
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-48 flex-1">
          <SearchField
            id="checkout-invoice-countries-search"
            value={search}
            placeholder={t('geography.countries_search')}
            onChange={setSearch}
            onSubmit={() => {}}
            embedded
          />
        </div>
        <button
          type="button"
          className="button-secondary"
          onClick={() => onChange([...new Set([...selected, ...covered])])}
        >
          {t('checkout.countries_select_covered')}
        </button>
        <button
          type="button"
          className="button-secondary"
          onClick={() => onChange([])}
        >
          {t('common.clear_selection')}
        </button>
      </div>
      <p className="text-sm text-slate-600">
        {t('common.selected', { count: selected.length })}
      </p>
      <div className="max-h-96 overflow-auto rounded-lg border border-slate-200">
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('geography.country')}</th>
              <th>{t('geography.country_code')}</th>
              <th className="text-right">{t('checkout.invoice_requests')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={3} className="py-8 text-center text-slate-500">
                  {t('common.no_results')}
                </td>
              </tr>
            )}
            {rows.map((country) => {
              const enabled = selected.includes(country.code);
              return (
                <tr key={country.code}>
                  <td className="font-semibold">
                    {country.name}
                    {!covered.includes(country.code) && (
                      <span className="mt-1 block text-xs font-normal text-slate-500">
                        {t('checkout.country_not_covered')}
                      </span>
                    )}
                  </td>
                  <td className="text-sm text-slate-600">{country.code}</td>
                  <td>
                    <div className="flex items-center justify-end gap-3">
                      <StatusBadge tone={enabled ? 'success' : 'neutral'}>
                        {t(enabled ? 'common.yes' : 'common.no')}
                      </StatusBadge>
                      <Switch
                        label={t('checkout.country_toggle', {
                          country: country.name,
                        })}
                        checked={enabled}
                        onChange={(checked) =>
                          onChange(
                            checked
                              ? [...selected, country.code]
                              : selected.filter(
                                  (code) => code !== country.code,
                                ),
                          )
                        }
                      />
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
