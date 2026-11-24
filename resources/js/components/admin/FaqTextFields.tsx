import AdminTabs from './AdminTabs';
import ContentLanguageSelector from './ContentLanguageSelector';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '../auth/FormField';
import RichText from './RichText';
import { useTranslation } from '../../lib/useTranslation';
import type { FaqText } from '../../types/faqs';
import type { SharedProps } from '../../types/page';

export default function FaqTextFields({
  prefix,
  translations,
  onChange,
  errors = {},
  disabled = false,
  locale: controlledLocale,
}: {
  prefix: string;
  translations: Record<string, FaqText>;
  onChange: (translations: Record<string, FaqText>) => void;
  errors?: Record<string, string>;
  disabled?: boolean;
  locale?: string;
}) {
  const t = useTranslation();
  const { i18n, faqFieldRules } = usePage<SharedProps>().props;
  const [localLocale, setLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : (i18n.locales[0]?.code ?? 'en'),
  );
  const locale = controlledLocale ?? localLocale;
  const text = translations[locale] ?? { question: '', answer: '' };
  const update = (field: keyof FaqText, value: string) =>
    onChange({ ...translations, [locale]: { ...text, [field]: value } });
  return (
    <div className="space-y-4">
      {controlledLocale === undefined && (
        <AdminTabs
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
            />
          }
        />
      )}
      <div role="tabpanel" className="space-y-4">
        <FormField
          id={prefix + '-' + locale + '-question'}
          label={t('faqs.question')}
          requiredIndicator={faqFieldRules?.question.required}
          maxLength={faqFieldRules?.question.maxLength}
          value={text.question}
          disabled={disabled}
          error={errors[prefix + '.' + locale + '.question']}
          onChange={(e) => update('question', e.target.value)}
        />
        <RichText
          id={prefix + '-' + locale + '-answer'}
          label={t('faqs.answer')}
          value={text.answer}
          onChange={(value) => update('answer', value)}
          disabled={disabled}
          maxLength={faqFieldRules?.answer.maxLength}
          invalid={!!errors[prefix + '.' + locale + '.answer']}
        />
        {errors[prefix + '.' + locale + '.answer'] && (
          <p role="alert" className="text-sm text-red-700">
            {errors[prefix + '.' + locale + '.answer']}
          </p>
        )}
      </div>
    </div>
  );
}
