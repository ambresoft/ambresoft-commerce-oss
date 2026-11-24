import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import Select from '../../../components/admin/Select';
import FaqTextFields from '../../../components/admin/FaqTextFields';
import AiChat from '../../../components/admin/AiChat';
import { htmlToText } from '../../../lib/htmlText';
import { useTranslation } from '../../../lib/useTranslation';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import type { AdminShell } from '../../../types/admin';
import type { FaqEditor, FaqType } from '../../../types/faqs';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & { faq: FaqEditor | null; defaultLocale: string };
export default function Form(props: Props) {
  return (
    <Editor
      key={(props.faq?.id ?? 'new') + ':' + (props.faq?.version ?? 0)}
      {...props}
    />
  );
}
function Editor({ faq, defaultLocale, ...shell }: Props) {
  const t = useTranslation();
  const {
    i18n,
    capabilities,
    faqAiReady = false,
  } = usePage<SharedProps>().props;
  const allowed = !!capabilities['faqs.manage'];
  const [tab, setTab] = useState<'general' | 'content' | 'ai'>('general');
  const [locale, setLocale] = useState(defaultLocale);
  const showAi = allowed && !!capabilities['faqs.generate'];
  const form = useForm({
    version: faq?.version ?? null,
    type: faq?.type ?? ('general' as FaqType),
    status: faq?.status ?? 'active',
    position: faq?.position ?? 0,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        faq?.translations[l.code] ?? { question: '', answer: '' },
      ]),
    ),
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const title = t(faq ? 'faqs.edit' : 'faqs.create');
  const remove = () => {
    if (faq && window.confirm(t('faqs.delete_confirm')))
      router.delete('/admin/faqs/' + faq.id, {
        data: { version: faq.version },
      });
  };
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div>
              <EditorStatusSelect
                aria-label={t('common.status')}
                id="faq-status"
                value={form.data.status}
                disabled={!allowed}
                options={[
                  { value: 'active', label: t('common.active') },
                  { value: 'disabled', label: t('common.inactive') },
                ]}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
              />
            </div>
          </>
        }
        heading={title}
        iconKey="faqs"
        backHref="/admin/faqs"
        backLabel={t('faqs.title')}
        actions={
          <>
            {allowed && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-faqs-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          faq && allowed && (
            <button
              type="button"
              className="button-secondary"
              onClick={remove}
              disabled={form.processing}
            >
              {t('common.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (faq) form.put('/admin/faqs/' + faq.id);
          else form.post('/admin/faqs');
        }}
        id="admin-faqs-form-0"
      >
        <AdminTabs
          label={t('faqs.title')}
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
            />
          }
          className="mb-4"
        >
          {(showAi
            ? (['general', 'content', 'ai'] as const)
            : (['general', 'content'] as const)
          ).map((item) => (
            <AdminTab
              key={item}
              type="button"
              role="tab"
              aria-selected={tab === item}
              onClick={() => setTab(item)}
              iconKey={item}
            >
              {t(`faqs.tab_${item}`)}
              {item !== 'ai' &&
                Object.keys(errors).some((key) =>
                  item === 'content'
                    ? key.startsWith('translations')
                    : !key.startsWith('translations'),
                ) && <span className="ml-1 text-red-700">●</span>}
            </AdminTab>
          ))}
        </AdminTabs>
        <FormErrors errors={errors} />
        <div role="tabpanel" className="mt-4" hidden={tab === 'ai'}>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-5"
          >
            {tab === 'general' ? (
              <>
                <div>
                  <label
                    htmlFor="faq-type"
                    className="mb-2 block text-sm font-medium"
                  >
                    {t('faqs.type')}
                  </label>
                  <Select
                    id="faq-type"
                    value={form.data.type}
                    disabled={!allowed || (faq?.links ?? 0) > 0}
                    invalid={!!errors.type}
                    options={['general', 'category', 'product'].map(
                      (value) => ({
                        value,
                        label: t(`faqs.type_${value}`),
                      }),
                    )}
                    onChange={(value) => form.setData('type', value as FaqType)}
                  />
                  <p className="mt-1 text-xs leading-5 text-slate-500">
                    {t('faqs.type_help')}
                  </p>
                </div>

                <FormField
                  id="faq-position"
                  label={t('faqs.position')}
                  type="number"
                  min={0}
                  max={2147483647}
                  value={form.data.position}
                  disabled={!allowed}
                  error={errors.position}
                  onChange={(e) =>
                    form.setData('position', Number(e.target.value))
                  }
                />
                {faq && (
                  <p className="text-sm text-slate-500">
                    {t('faqs.link_count', { count: faq.links })}
                  </p>
                )}
              </>
            ) : (
              <>
                <p className="text-xs leading-5 text-slate-500">
                  {t('faqs.content_help')}
                </p>
                <FaqTextFields
                  locale={locale}
                  prefix="translations"
                  translations={form.data.translations}
                  errors={errors}
                  disabled={!allowed}
                  onChange={(translations) =>
                    form.setData('translations', translations)
                  }
                />
              </>
            )}
          </UnifiedCard>
        </div>
        {showAi && (
          // Hidden rather than unmounted, so the conversation survives switching tabs.
          <div role="tabpanel" className="mt-4" hidden={tab !== 'ai'}>
            <AiChat<{
              translations: Record<
                string,
                { question: string; answer: string }
              >;
            }>
              endpoint="/admin/faqs/assistant"
              ready={faqAiReady}
              current={() => ({
                translations: Object.fromEntries(
                  Object.entries(form.data.translations).map(([code, text]) => [
                    code,
                    {
                      question: text.question,
                      answer: htmlToText(text.answer),
                    },
                  ]),
                ),
              })}
              onApply={(draft) =>
                form.setData((current) => ({
                  ...current,
                  translations: {
                    ...current.translations,
                    ...draft.translations,
                  },
                }))
              }
              preview={
                <UnifiedCard
                  title={t('common.preview')}
                  iconKey="preview"
                  bodyClassName="p-5 space-y-2"
                >
                  <p className="font-semibold">
                    {form.data.translations[locale]?.question}
                  </p>
                  <p className="text-sm whitespace-pre-line text-slate-600">
                    {htmlToText(form.data.translations[locale]?.answer ?? '')}
                  </p>
                </UnifiedCard>
              }
              label={t('faqs.tab_ai')}
              intro={t('faqs.ai_chat_intro')}
              placeholder={t('faqs.ai_chat_placeholder')}
              consent={t('faqs.ai_chat_consent')}
              notConfigured={t('faqs.ai_not_configured')}
              examples={[
                t('faqs.ai_example_1'),
                t('faqs.ai_example_2'),
                t('faqs.ai_example_3'),
              ]}
            />
          </div>
        )}
      </form>
    </AdminLayout>
  );
}
