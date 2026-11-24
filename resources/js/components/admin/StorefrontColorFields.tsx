import { useTranslation } from '../../lib/useTranslation';

export const colorNames = [
  'primary',
  'primary_hover',
  'on_primary',
  'text',
  'background',
  'sale',
] as const;
export type ColorName = (typeof colorNames)[number];
export type StorefrontColors = Record<ColorName, string>;
/** The colors form: the six brand colors and the sign-in header's own color (empty: the primary color). */
export type StorefrontColorForm = StorefrontColors & { auth_header: string };
const HEX = /^#[0-9a-fA-F]{6}$/;
export const validColors = (values: StorefrontColorForm) =>
  colorNames.every((name) => HEX.test(values[name])) &&
  (values.auth_header === '' || HEX.test(values.auth_header));

/** Brand colors of the storefront. The caller owns the form and its save request. */
export default function StorefrontColorFields({
  values,
  saved,
  defaults,
  canManage,
  onChange,
}: {
  values: StorefrontColorForm;
  saved: StorefrontColors;
  defaults: StorefrontColors;
  canManage: boolean;
  onChange: (name: ColorName | 'auth_header', value: string) => void;
}) {
  const t = useTranslation();
  const valid = (name: ColorName) => HEX.test(values[name]);
  // The color picker only accepts complete hex values; keep showing the saved one while typing.
  const shown = (name: ColorName) => (valid(name) ? values[name] : saved[name]);
  return (
    <div>
      <h2 className="text-base font-semibold">{t('layouts.colors_title')}</h2>
      <p className="mt-1 mb-5 max-w-3xl text-xs leading-5 text-slate-500">
        {t('layouts.colors_description')}
      </p>
      <div>
        <section className="space-y-5 rounded-xl border border-slate-200 bg-white p-5">
          {colorNames.map((name) => (
            <div key={name} className="space-y-1">
              <label htmlFor={`color-${name}`} className="text-sm font-medium">
                {t(`layouts.color_${name}`)}
              </label>
              <p className="text-xs text-slate-500">
                {t(`layouts.color_${name}_help`)}
              </p>
              <div className="flex flex-wrap items-center gap-3">
                <input
                  type="color"
                  aria-label={t(`layouts.color_${name}`)}
                  className="h-10 w-14 cursor-pointer rounded border border-slate-200 bg-transparent p-1"
                  value={shown(name)}
                  disabled={!canManage}
                  onChange={(e) => onChange(name, e.target.value)}
                />
                <input
                  id={`color-${name}`}
                  className="w-32 rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
                  value={values[name]}
                  maxLength={7}
                  spellCheck={false}
                  disabled={!canManage}
                  aria-invalid={!valid(name)}
                  onChange={(e) => onChange(name, e.target.value)}
                />
                <button
                  type="button"
                  className="text-link text-sm"
                  disabled={!canManage || values[name] === defaults[name]}
                  onClick={() => onChange(name, defaults[name])}
                >
                  {t('layouts.color_reset')}
                </button>
              </div>
              {!valid(name) && (
                <p role="alert" className="text-sm text-red-700">
                  {t('layouts.color_hex_invalid')}
                </p>
              )}
            </div>
          ))}
        </section>
        <section className="mt-5 space-y-1 rounded-xl border border-slate-200 bg-white p-5">
          <label htmlFor="color-auth_header" className="text-sm font-medium">
            {t('layouts.color_auth_header')}
          </label>
          <p className="text-xs text-slate-500">
            {t('layouts.color_auth_header_help')}
          </p>
          <div className="flex flex-wrap items-center gap-3">
            <input
              type="color"
              aria-label={t('layouts.color_auth_header')}
              className="h-10 w-14 cursor-pointer rounded border border-slate-200 bg-transparent p-1"
              value={
                HEX.test(values.auth_header)
                  ? values.auth_header
                  : shown('primary')
              }
              disabled={!canManage}
              onChange={(e) => onChange('auth_header', e.target.value)}
            />
            <input
              id="color-auth_header"
              className="w-32 rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
              value={values.auth_header}
              placeholder={shown('primary')}
              maxLength={7}
              spellCheck={false}
              disabled={!canManage}
              aria-invalid={
                values.auth_header !== '' && !HEX.test(values.auth_header)
              }
              onChange={(e) => onChange('auth_header', e.target.value)}
            />
            <button
              type="button"
              className="text-link text-sm"
              disabled={!canManage || values.auth_header === ''}
              onClick={() => onChange('auth_header', '')}
            >
              {t('layouts.color_auth_header_reset')}
            </button>
          </div>
          {values.auth_header !== '' && !HEX.test(values.auth_header) && (
            <p role="alert" className="text-sm text-red-700">
              {t('layouts.color_hex_invalid')}
            </p>
          )}
        </section>
      </div>
    </div>
  );
}
