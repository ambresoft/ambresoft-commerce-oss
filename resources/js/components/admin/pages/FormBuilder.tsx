import { Fragment, useState } from 'react';
import RichText from '../RichText';
import IconButton from '../list/IconButton';
import { Check, Row, Section } from '../form/fields';
import { pageLabels } from '../../../lib/pageLabels';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  FieldType,
  FormField,
  FormVariant,
  PageFormDefinition,
} from '../../../types/pages';

const TYPES = Object.keys(pageLabels.fieldType) as FieldType[];
const CHOICE: FieldType[] = ['select', 'radio', 'checkboxes'];
const DECORATIVE: FieldType[] = ['header', 'separator'];
const SUBJECT: FieldType[] = ['input', 'select', 'radio', 'email'];
let sequence = 0;
const newKey = (prefix: string) => `${prefix}-${Date.now()}-${++sequence}`;

export const emptyForm = (): PageFormDefinition => ({
  enabled: false,
  placement: 'after',
  subject_mode: 'form_name',
  subject_field: '',
  recipients: '',
  translations: {},
  fields: [],
});

/**
 * Form builder for a page (like CS-Cart's form builder): fields with per-language names, choice options,
 * subject, recipients, submission text and where the form appears. It only defines the form.
 */
export default function FormBuilder({
  form,
  onChange,
  locale,
  defaultLocale,
  errors,
  disabled,
  onInsertMarker,
}: {
  form: PageFormDefinition;
  onChange: (form: PageFormDefinition) => void;
  locale: string;
  defaultLocale: string;
  errors: Record<string, string>;
  disabled: boolean;
  onInsertMarker: () => void;
}) {
  const t = useTranslation();
  const [open, setOpen] = useState<string[]>([]);
  const set = (patch: Partial<PageFormDefinition>) =>
    onChange({ ...form, ...patch });
  const setField = (index: number, patch: Partial<FormField>) =>
    set({
      fields: form.fields.map((field, i) =>
        i === index ? { ...field, ...patch } : field,
      ),
    });
  const setVariants = (index: number, variants: FormVariant[]) =>
    setField(index, { variants });
  const text = form.translations[locale] ?? {
    submit_text: '',
    custom_subject: '',
  };
  const setText = (patch: Partial<typeof text>) =>
    set({
      translations: { ...form.translations, [locale]: { ...text, ...patch } },
    });
  const fieldName = (field: FormField) =>
    field.names[locale] ||
    field.names[defaultLocale] ||
    t('pages.field_unnamed');
  const subjectValue =
    form.subject_mode === 'field'
      ? 'field:' + form.subject_field
      : form.subject_mode;
  const nextPosition = (items: { position: number }[]) =>
    items.reduce((max, item) => Math.max(max, item.position), 0) + 10;

  return (
    <>
      <Section title={t('pages.tab_form')} iconKey="form">
        <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
          {t('pages.form_intro')}
        </p>
        <Row id="form-enabled" label={t('pages.form_enabled')}>
          <Check
            id="form-enabled"
            checked={form.enabled}
            disabled={disabled}
            onChange={(enabled) => set({ enabled })}
          />
        </Row>
        <Row
          id="form-placement-after"
          label={t('pages.form_placement')}
          help={t('pages.placement_help')}
          error={errors['form.placement']}
        >
          <div
            role="radiogroup"
            aria-label={t('pages.form_placement')}
            className="space-y-1.5 pt-2"
          >
            {(['before', 'after', 'marker'] as const).map((placement) => (
              <label
                key={placement}
                className="flex items-center gap-2 text-sm"
              >
                <input
                  id={'form-placement-' + placement}
                  type="radio"
                  name="form-placement"
                  className="size-4 accent-[var(--color-primary)]"
                  disabled={disabled}
                  checked={form.placement === placement}
                  onChange={() => set({ placement })}
                />
                {t(pageLabels.placement[placement])}
              </label>
            ))}
          </div>
          {form.placement === 'marker' && !disabled && (
            <button
              type="button"
              className="button-secondary mt-2"
              onClick={onInsertMarker}
            >
              {t('pages.form_insert_marker')}
            </button>
          )}
        </Row>
        <Row
          id="form-subject"
          label={t('pages.form_subject')}
          help={t('pages.subject_help')}
          error={errors['form.subject_field'] ?? errors['form.subject_mode']}
        >
          <select
            id="form-subject"
            className="field max-w-md"
            disabled={disabled}
            value={subjectValue}
            onChange={(e) => {
              const value = e.target.value;
              if (value.startsWith('field:'))
                set({ subject_mode: 'field', subject_field: value.slice(6) });
              else
                set({
                  subject_mode: value as 'form_name' | 'custom',
                  subject_field: '',
                });
            }}
          >
            <optgroup label={t('pages.subject_fields_group')}>
              {form.fields
                .filter((field) => SUBJECT.includes(field.type))
                .map((field) => (
                  <option key={field.key} value={'field:' + field.key}>
                    {fieldName(field)}
                  </option>
                ))}
            </optgroup>
            <option value="form_name">{t('pages.subject_form_name')}</option>
            <option value="custom">{t('pages.subject_custom')}</option>
          </select>
        </Row>
        {form.subject_mode === 'custom' && (
          <Row
            id={'form-custom-subject-' + locale}
            label={t('pages.form_custom_subject')}
            required={locale === defaultLocale}
            error={errors[`form.translations.${locale}.custom_subject`]}
          >
            <input
              id={'form-custom-subject-' + locale}
              className="field"
              maxLength={200}
              disabled={disabled}
              value={text.custom_subject}
              onChange={(e) => setText({ custom_subject: e.target.value })}
            />
          </Row>
        )}
        <Row
          id={'form-submit-text-' + locale}
          label={t('pages.form_submit_text')}
          help={t('pages.form_submit_help')}
        >
          <RichText
            key={'submit-' + locale}
            id={'form-submit-text-' + locale}
            label={t('pages.form_submit_text')}
            profile="page"
            value={text.submit_text}
            disabled={disabled}
            onChange={(html) => setText({ submit_text: html })}
          />
        </Row>
        <Row
          id="form-recipients"
          label={t('pages.form_recipients')}
          help={t('pages.form_recipients_help')}
          required={form.enabled}
          error={errors['form.recipients']}
        >
          <input
            id="form-recipients"
            type="text"
            inputMode="email"
            className="field max-w-xl"
            maxLength={1000}
            disabled={disabled}
            value={form.recipients}
            placeholder="info@example.gr"
            onChange={(e) => set({ recipients: e.target.value })}
          />
        </Row>
      </Section>

      <Section title={t('pages.form_fields')} iconKey="features">
        {errors['form.fields'] && (
          <p role="alert" className="text-sm text-red-700">
            {errors['form.fields']}
          </p>
        )}
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th className="w-10" />
                <th className="w-24">{t('pages.field_position')}</th>
                <th>{t('pages.field_name')}</th>
                <th className="w-56">{t('pages.field_type')}</th>
                <th className="w-24 text-center">
                  {t('pages.field_required')}
                </th>
                <th className="w-36">{t('pages.field_status')}</th>
                <th className="w-12" />
              </tr>
            </thead>
            <tbody>
              {form.fields.length === 0 && (
                <tr>
                  <td colSpan={7} className="py-8 text-center text-slate-500">
                    {t('pages.form_fields_empty')}
                  </td>
                </tr>
              )}
              {form.fields.map((field, index) => {
                const choice = CHOICE.includes(field.type);
                const expanded = choice && open.includes(field.key);
                const prefix = `form.fields.${index}.`;
                const name = fieldName(field);
                return (
                  <Fragment key={field.key}>
                    <tr>
                      <td>
                        {choice && (
                          <button
                            type="button"
                            className="icon-button"
                            aria-expanded={expanded}
                            aria-label={t('pages.field_toggle', { name })}
                            onClick={() =>
                              setOpen((keys) =>
                                keys.includes(field.key)
                                  ? keys.filter((k) => k !== field.key)
                                  : [...keys, field.key],
                              )
                            }
                          >
                            <span
                              aria-hidden="true"
                              className={`inline-block transition-transform ${expanded ? 'rotate-90' : ''}`}
                            >
                              ▸
                            </span>
                          </button>
                        )}
                      </td>
                      <td>
                        <input
                          type="number"
                          className="field w-20"
                          aria-label={t('pages.field_position') + ': ' + name}
                          disabled={disabled}
                          value={field.position}
                          onChange={(e) =>
                            setField(index, {
                              position: Number(e.target.value),
                            })
                          }
                        />
                      </td>
                      <td>
                        <input
                          className="field"
                          aria-label={
                            t('pages.field_name') +
                            ' · ' +
                            locale +
                            ': ' +
                            (index + 1)
                          }
                          maxLength={200}
                          disabled={disabled}
                          value={field.names[locale] ?? ''}
                          placeholder={
                            locale !== defaultLocale
                              ? field.names[defaultLocale]
                              : undefined
                          }
                          aria-invalid={
                            errors[prefix + 'names.' + locale]
                              ? true
                              : undefined
                          }
                          onChange={(e) =>
                            setField(index, {
                              names: {
                                ...field.names,
                                [locale]: e.target.value,
                              },
                            })
                          }
                        />
                        {(errors[prefix + 'names.' + locale] ||
                          errors[prefix + 'variants']) && (
                          <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors[prefix + 'names.' + locale] ??
                              errors[prefix + 'variants']}
                          </p>
                        )}
                      </td>
                      <td>
                        <select
                          className="field"
                          aria-label={t('pages.field_type') + ': ' + name}
                          disabled={disabled}
                          value={field.type}
                          onChange={(e) => {
                            const type = e.target.value as FieldType;
                            setField(index, {
                              type,
                              required: DECORATIVE.includes(type)
                                ? false
                                : field.required,
                              variants:
                                CHOICE.includes(type) &&
                                field.variants.length === 0
                                  ? [
                                      {
                                        key: newKey('v'),
                                        position: 10,
                                        labels: {},
                                      },
                                    ]
                                  : field.variants,
                            });
                            if (CHOICE.includes(type))
                              setOpen((keys) => [
                                ...new Set([...keys, field.key]),
                              ]);
                          }}
                        >
                          {TYPES.map((type) => (
                            <option key={type} value={type}>
                              {t(pageLabels.fieldType[type])}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="text-center">
                        <input
                          type="checkbox"
                          className="size-4 accent-[var(--color-primary)]"
                          aria-label={t('pages.field_required') + ': ' + name}
                          disabled={disabled || DECORATIVE.includes(field.type)}
                          checked={field.required}
                          onChange={(e) =>
                            setField(index, { required: e.target.checked })
                          }
                        />
                      </td>
                      <td>
                        <select
                          className="field"
                          aria-label={t('pages.field_status') + ': ' + name}
                          disabled={disabled}
                          value={field.status}
                          onChange={(e) =>
                            setField(index, {
                              status: e.target.value as FormField['status'],
                            })
                          }
                        >
                          <option value="active">
                            {t('pages.field_active')}
                          </option>
                          <option value="disabled">
                            {t('pages.field_disabled')}
                          </option>
                        </select>
                      </td>
                      <td>
                        {!disabled && (
                          <IconButton
                            icon="trash"
                            danger
                            label={t('pages.field_delete', { name })}
                            onClick={() =>
                              set({
                                fields: form.fields.filter(
                                  (_, i) => i !== index,
                                ),
                                ...(form.subject_mode === 'field' &&
                                form.subject_field === field.key
                                  ? {
                                      subject_mode: 'form_name' as const,
                                      subject_field: '',
                                    }
                                  : {}),
                              })
                            }
                          />
                        )}
                      </td>
                    </tr>
                    {expanded && (
                      <tr className="bg-slate-50">
                        <td />
                        <td colSpan={6}>
                          <p className="mb-2 text-xs font-semibold tracking-wide text-slate-600 uppercase">
                            {t('pages.field_variants')}
                          </p>
                          <ul className="space-y-2">
                            {field.variants.map((variant, j) => (
                              <li
                                key={variant.key ?? j}
                                className="flex flex-wrap items-center gap-2"
                              >
                                <input
                                  type="number"
                                  className="field w-20"
                                  aria-label={
                                    t('pages.field_position') +
                                    ': ' +
                                    (variant.labels[locale] ?? j + 1)
                                  }
                                  disabled={disabled}
                                  value={variant.position}
                                  onChange={(e) =>
                                    setVariants(
                                      index,
                                      field.variants.map((v, k) =>
                                        k === j
                                          ? {
                                              ...v,
                                              position: Number(e.target.value),
                                            }
                                          : v,
                                      ),
                                    )
                                  }
                                />
                                <input
                                  className="field min-w-48 flex-1"
                                  aria-label={
                                    t('pages.variant_label') +
                                    ' · ' +
                                    locale +
                                    ': ' +
                                    (j + 1)
                                  }
                                  maxLength={200}
                                  disabled={disabled}
                                  value={variant.labels[locale] ?? ''}
                                  placeholder={
                                    locale !== defaultLocale
                                      ? variant.labels[defaultLocale]
                                      : undefined
                                  }
                                  onChange={(e) =>
                                    setVariants(
                                      index,
                                      field.variants.map((v, k) =>
                                        k === j
                                          ? {
                                              ...v,
                                              labels: {
                                                ...v.labels,
                                                [locale]: e.target.value,
                                              },
                                            }
                                          : v,
                                      ),
                                    )
                                  }
                                />
                                {!disabled && (
                                  <div className="flex gap-1">
                                    <button
                                      type="button"
                                      className="button-secondary"
                                      onClick={() =>
                                        setVariants(index, [
                                          ...field.variants.slice(0, j + 1),
                                          {
                                            key: newKey('v'),
                                            position: variant.position + 1,
                                            labels: {},
                                          },
                                          ...field.variants.slice(j + 1),
                                        ])
                                      }
                                    >
                                      {t('pages.variant_add')}
                                    </button>
                                    <button
                                      type="button"
                                      className="button-secondary"
                                      onClick={() =>
                                        setVariants(index, [
                                          ...field.variants.slice(0, j + 1),
                                          {
                                            ...variant,
                                            key: newKey('v'),
                                            position: variant.position + 1,
                                            labels: { ...variant.labels },
                                          },
                                          ...field.variants.slice(j + 1),
                                        ])
                                      }
                                    >
                                      {t('pages.variant_clone')}
                                    </button>
                                    <IconButton
                                      icon="trash"
                                      danger
                                      label={
                                        t('pages.variant_delete') +
                                        ': ' +
                                        (j + 1)
                                      }
                                      onClick={() =>
                                        setVariants(
                                          index,
                                          field.variants.filter(
                                            (_, k) => k !== j,
                                          ),
                                        )
                                      }
                                    />
                                  </div>
                                )}
                              </li>
                            ))}
                          </ul>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
        {!disabled && (
          <button
            type="button"
            className="button-secondary"
            onClick={() =>
              set({
                fields: [
                  ...form.fields,
                  {
                    key: newKey('f'),
                    id: null,
                    position: nextPosition(form.fields),
                    type: 'input',
                    required: false,
                    status: 'active',
                    names: {},
                    variants: [],
                  },
                ],
              })
            }
          >
            {t('pages.field_add')}
          </button>
        )}
      </Section>
    </>
  );
}
