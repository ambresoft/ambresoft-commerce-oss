import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { Choice, ListEditor } from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

function initial(item: ListEditor | null, locales: { code: string }[]) {
  return {
    version: item?.version ?? 1,
    status: item?.status ?? 'active',
    from_name: item?.from_name ?? '',
    from_email: item?.from_email ?? '',
    reply_to: item?.reply_to ?? '',
    confirmation_id: item?.confirmation_id ?? '',
    show_at_checkout: item?.show_at_checkout ?? false,
    show_on_registration: item?.show_on_registration ?? false,
    translations: Object.fromEntries(
      locales.map((l) => [
        l.code,
        item?.translations[l.code] ?? { name: '', description: '' },
      ]),
    ) as Record<string, { name: string; description: string }>,
  };
}

export default function ListForm({
  item,
  autoresponders,
  defaultLocale,
  ...shell
}: AdminShell & {
  item: ListEditor | null;
  autoresponders: Choice[];
  defaultLocale: string;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const form = useForm(initial(item, i18n.locales));
  const errors = form.errors as Record<string, string>;
  const text = form.data.translations[locale] ?? { name: '', description: '' };
  const setText = (patch: Partial<typeof text>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...patch },
    });
  const sync = (page: { props: unknown }) => {
    const data = initial(
      (page.props as { item: ListEditor | null }).item,
      i18n.locales,
    );
    form.setDefaults(data);
    form.setData(data);
  };
  const title = item
    ? (item.translations[defaultLocale]?.name ?? '')
    : t('newsletters.add');

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div className="flex items-center gap-2">
              <EditorStatusSelect
                disabled={form.processing}
                id="list-status"
                aria-label={t('common.status')}
                value={form.data.status}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
                options={[
                  { value: 'active', label: t('newsletters.active') },
                  { value: 'disabled', label: t('newsletters.inactive') },
                ]}
              />
            </div>
          </>
        }
        heading={title}
        iconKey="emails"
        backHref="/admin/newsletter-lists"
        backLabel={t('newsletters.lists_title')}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-newsletters-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          const options = { preserveScroll: true, onSuccess: sync };
          if (item) form.put('/admin/newsletter-lists/' + item.id, options);
          else form.post('/admin/newsletter-lists', options);
        }}
        id="admin-newsletters-form-0"
      >
        <FormErrors errors={errors} />
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 space-y-5"
          >
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
            <FormField
              id={'list-name-' + locale}
              label={t('common.name') + ' · ' + locale}
              requiredIndicator={locale === defaultLocale}
              maxLength={150}
              value={text.name}
              error={errors['translations.' + locale + '.name']}
              onChange={(e) => setText({ name: e.target.value })}
            />
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="list-description"
              >
                {t('newsletters.description')}
              </label>
              <textarea
                id="list-description"
                className="field min-h-20"
                maxLength={500}
                value={text.description}
                onChange={(e) => setText({ description: e.target.value })}
              />
            </div>
          </UnifiedCard>
        </section>
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 grid gap-5 sm:grid-cols-2"
          >
            <FormField
              id="from-name"
              label={t('newsletters.from_name')}
              hint={t('newsletters.sender_hint')}
              maxLength={100}
              value={form.data.from_name}
              error={errors.from_name}
              onChange={(e) => form.setData('from_name', e.target.value)}
            />
            <FormField
              id="from-email"
              type="email"
              label={t('newsletters.from_email')}
              maxLength={254}
              value={form.data.from_email}
              error={errors.from_email}
              onChange={(e) => form.setData('from_email', e.target.value)}
            />
            <FormField
              id="reply-to"
              type="email"
              label={t('newsletters.reply_to')}
              maxLength={254}
              value={form.data.reply_to}
              error={errors.reply_to}
              onChange={(e) => form.setData('reply_to', e.target.value)}
            />
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="confirmation"
              >
                {t('newsletters.confirmation')}
              </label>
              <Select
                id="confirmation"
                aria-label={t('newsletters.confirmation')}
                value={form.data.confirmation_id}
                invalid={!!errors.confirmation_id}
                onChange={(value) => form.setData('confirmation_id', value)}
                options={[
                  { value: '', label: t('newsletters.no_confirmation') },
                  ...autoresponders.map((a) => ({
                    value: a.id,
                    label: a.name,
                  })),
                ]}
              />
              <p className="text-xs text-slate-500">
                {t('newsletters.confirmation_hint')}
              </p>
            </div>
            {(['show_on_registration', 'show_at_checkout'] as const).map(
              (key) => (
                <label key={key} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={form.data[key]}
                    onChange={(e) => form.setData(key, e.target.checked)}
                  />
                  <span>
                    {t(
                      key === 'show_on_registration'
                        ? 'newsletters.show_on_registration'
                        : 'newsletters.show_at_checkout',
                    )}
                  </span>
                </label>
              ),
            )}
          </UnifiedCard>
        </section>
      </form>
    </AdminLayout>
  );
}
