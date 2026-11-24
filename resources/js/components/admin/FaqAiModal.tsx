import AdminTabs from './AdminTabs';
import ContentLanguageSelector from './ContentLanguageSelector';
import { useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Select from './Select';
import FormField from '../auth/FormField';
import { useTranslation } from '../../lib/useTranslation';
import { faqRequest } from '../../lib/faqRequest';
import type { FaqDraft, FaqSource } from '../../types/faqs';
import type { SharedProps } from '../../types/page';

const answerHtml = (text: string) =>
  '<p>' +
  text
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('\n', '<br>') +
  '</p>';

export default function FaqAiModal({
  source,
  onAdd,
  disabled = false,
  single = false,
  maxEntries = 10,
}: {
  source: FaqSource;
  onAdd: (drafts: FaqDraft[]) => void;
  disabled?: boolean;
  single?: boolean;
  maxEntries?: number;
}) {
  const t = useTranslation();
  const {
    i18n,
    capabilities,
    faqAiReady = false,
    faqFieldRules,
  } = usePage<SharedProps>().props;
  const dialog = useRef<HTMLDialogElement>(null);
  const [language, setLanguage] = useState('auto');
  const [locale, setLocale] = useState(i18n.locale);
  const limit = single ? 1 : maxEntries;
  const [count, setCount] = useState(
    Math.max(1, Math.min(single ? 1 : 5, limit)),
  );
  const [entries, setEntries] = useState<(FaqDraft & { selected: boolean })[]>(
    [],
  );
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState('');
  if (!capabilities['faqs.generate'] || !capabilities['faqs.manage'])
    return null;
  const generate = async (index?: number) => {
    setError('');
    if (!faqAiReady) {
      setError(t('faqs.ai_not_configured'));
      return;
    }
    if (index === undefined && (count < 1 || count > limit)) {
      setError(t('faqs.ai_count_invalid', { max: limit }));
      return;
    }
    setProcessing(true);
    try {
      const response = await faqRequest<{ entries: FaqDraft[] }>(
        '/admin/faqs/generate',
        {
          ...source,
          locale: language,
          count: index === undefined ? count : 1,
          question:
            index === undefined
              ? undefined
              : entries[index].translations[locale]?.question,
        },
      );
      if (index === undefined)
        setEntries(
          response.entries.map((entry) => ({ ...entry, selected: true })),
        );
      else
        setEntries((current) =>
          current.map((entry, i) =>
            i === index
              ? {
                  ...entry,
                  translations: {
                    ...entry.translations,
                    ...response.entries[0].translations,
                  },
                }
              : entry,
          ),
        );
    } catch (failure) {
      setError(
        failure instanceof Error &&
          !['request_failed', 'rate_limited'].includes(failure.message)
          ? failure.message
          : t(
              failure instanceof Error && failure.message === 'rate_limited'
                ? 'faqs.ai_rate_limited'
                : 'faqs.ai_error_connection_error',
            ),
      );
    } finally {
      setProcessing(false);
    }
  };
  const selected = entries.filter((entry) => entry.selected);
  const complete = selected.every((entry) =>
    i18n.locales.every(
      (l) =>
        entry.translations[l.code]?.question.trim() &&
        entry.translations[l.code]?.answer.trim(),
    ),
  );
  const edit = (index: number, field: 'question' | 'answer', value: string) =>
    setEntries((current) =>
      current.map((entry, i) =>
        i === index
          ? {
              ...entry,
              translations: {
                ...entry.translations,
                [locale]: {
                  ...(entry.translations[locale] ?? {
                    question: '',
                    answer: '',
                  }),
                  [field]: value,
                },
              },
            }
          : entry,
      ),
    );
  return (
    <>
      <button
        type="button"
        className="button-secondary"
        disabled={disabled}
        onClick={() => dialog.current?.showModal()}
      >
        {t('faqs.generate')}
      </button>
      <dialog
        ref={dialog}
        aria-label={t('faqs.ai_preview')}
        className="m-auto max-h-[90dvh] w-[min(56rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 text-slate-900 shadow-xl backdrop:bg-black/30 sm:p-6"
        onCancel={(e) => {
          if (processing) e.preventDefault();
        }}
      >
        <div className="mb-4 flex items-center justify-between gap-3">
          <h2 className="text-xl font-semibold">{t('faqs.ai_preview')}</h2>
          <button
            type="button"
            className="button-secondary"
            disabled={processing}
            onClick={() => dialog.current?.close()}
          >
            {t('common.close')}
          </button>
        </div>
        <p className="mb-4 text-sm text-slate-500">{t('faqs.ai_consent')}</p>
        {!faqAiReady && (
          <p role="status" className="mb-4 text-sm text-slate-500">
            {t('faqs.ai_not_configured')}
            {capabilities['settings.manage'] && (
              <>
                {' '}
                <Link
                  className="text-link"
                  href="/admin/settings/general?tab=ai"
                >
                  {t('ai.settings')}
                </Link>
              </>
            )}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-[1fr_8rem_auto] sm:items-end">
          <div>
            <label
              htmlFor="faq-ai-language"
              className="mb-2 block text-sm font-medium"
            >
              {t('faqs.ai_language')}
            </label>
            <Select
              id="faq-ai-language"
              value={language}
              disabled={processing}
              options={[
                { value: 'auto', label: t('faqs.ai_auto') },
                ...i18n.locales.map((l) => ({ value: l.code, label: l.name })),
              ]}
              onChange={setLanguage}
            />
          </div>
          <FormField
            id="faq-ai-count"
            label={t('faqs.ai_count')}
            type="number"
            min={1}
            max={limit}
            disabled={processing || single}
            value={count}
            onChange={(e) => setCount(Number(e.target.value))}
          />
          <button
            type="button"
            className="button-primary"
            disabled={processing || !faqAiReady}
            onClick={() => void generate()}
          >
            {t(processing ? 'common.wait' : 'faqs.generate')}
          </button>
        </div>
        {error && (
          <p
            role="alert"
            className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
          >
            {error}
          </p>
        )}
        {entries.length > 0 && (
          <>
            <AdminTabs
              language={
                <ContentLanguageSelector
                  languages={i18n.locales}
                  value={locale}
                  onChange={setLocale}
                />
              }
            />
            <div className="space-y-5">
              {entries.map((entry, index) => (
                <section
                  key={index}
                  className="space-y-3 border-t border-slate-200 pt-4"
                >
                  <div className="flex items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm font-medium">
                      <input
                        type="checkbox"
                        className="size-4"
                        disabled={processing}
                        checked={entry.selected}
                        onChange={(e) =>
                          setEntries((current) =>
                            current.map((row, i) =>
                              i === index
                                ? { ...row, selected: e.target.checked }
                                : row,
                            ),
                          )
                        }
                      />
                      {t('faqs.ai_entry', { number: index + 1 })}
                    </label>
                    <button
                      type="button"
                      className="button-secondary"
                      disabled={processing || !faqAiReady}
                      onClick={() => void generate(index)}
                    >
                      {t('faqs.regenerate')}
                    </button>
                  </div>
                  <FormField
                    id={'ai-question-' + index}
                    label={t('faqs.question')}
                    maxLength={faqFieldRules?.question.maxLength}
                    value={entry.translations[locale]?.question ?? ''}
                    disabled={processing}
                    onChange={(e) => edit(index, 'question', e.target.value)}
                  />
                  <label
                    htmlFor={'ai-answer-' + index}
                    className="block text-sm font-medium"
                  >
                    {t('faqs.answer')}
                  </label>
                  <textarea
                    id={'ai-answer-' + index}
                    className="field min-h-24"
                    maxLength={faqFieldRules?.answer.maxLength}
                    disabled={processing}
                    value={entry.translations[locale]?.answer ?? ''}
                    onChange={(e) => edit(index, 'answer', e.target.value)}
                  />
                </section>
              ))}
            </div>
            <p className="mt-4 text-xs leading-5 text-slate-500">
              {t('faqs.ai_review_help')}
            </p>
            <div className="mt-5 flex justify-end">
              <button
                type="button"
                className="button-primary"
                disabled={processing || selected.length === 0 || !complete}
                onClick={() => {
                  onAdd(
                    selected.map((entry) => ({
                      translations: Object.fromEntries(
                        Object.entries(entry.translations).map(
                          ([code, text]) => [
                            code,
                            {
                              question: text.question,
                              answer: answerHtml(text.answer),
                            },
                          ],
                        ),
                      ),
                    })),
                  );
                  setEntries([]);
                  dialog.current?.close();
                }}
              >
                {t('faqs.add_selected')}
              </button>
            </div>
          </>
        )}
      </dialog>
    </>
  );
}
