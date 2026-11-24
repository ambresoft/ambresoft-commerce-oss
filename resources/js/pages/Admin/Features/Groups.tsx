import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import RichText from '../../../components/admin/RichText';
import FormErrors from '../../../components/admin/FormErrors';
import FeatureImpact from '../../../components/admin/FeatureImpact';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { FeatureGroup, ImpactPreview } from '../../../types/features';
export default function Groups({
  groups,
  defaultLocale,
  impactPreview,
  ...shell
}: AdminShell & {
  groups: FeatureGroup[];
  defaultLocale: string;
  impactPreview: ImpactPreview;
}) {
  const t = useTranslation();
  const [confirmedToken, setConfirmedToken] = useState('');
  const confirmed = !!impactPreview && confirmedToken === impactPreview.token;
  const setConfirmed = (value: boolean) =>
    setConfirmedToken(value ? (impactPreview?.token ?? '') : '');
  return (
    <AdminLayout title={t('features.groups')} {...shell}>
      <EditorHeader
        heading={t('features.groups')}
        iconKey="features"
        backHref="/admin/features"
        backLabel={t('features.title')}
      />
      <FeatureImpact
        preview={impactPreview}
        confirmed={confirmed}
        onConfirm={setConfirmed}
      />
      <div className="space-y-4">
        {groups.map((g) =>
          g.protected ? (
            <p key={g.id} className="rounded-lg bg-white p-5">
              {g.name} · {t('features.general_protected')}
            </p>
          ) : (
            <GroupForm
              key={g.id + ':' + g.version}
              group={g}
              defaultLocale={defaultLocale}
              confirmation={{
                confirmed,
                impact_token: impactPreview?.token ?? null,
              }}
            />
          ),
        )}
        <GroupForm
          key={'new:' + groups.length}
          group={null}
          defaultLocale={defaultLocale}
          confirmation={{
            confirmed,
            impact_token: impactPreview?.token ?? null,
          }}
        />
      </div>
    </AdminLayout>
  );
}
function GroupForm({
  group,
  defaultLocale,
  confirmation,
}: {
  group: FeatureGroup | null;
  defaultLocale: string;
  confirmation: { confirmed: boolean; impact_token: string | null };
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const form = useForm({
    version: group?.version,
    action: 'save',
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        {
          name: group?.translations[l.code]?.name ?? '',
          description: group?.translations[l.code]?.description ?? '',
        },
      ]),
    ),
  });
  useDirtyForm(form);
  const submit = (action: string) => {
    form.transform((d) => ({ ...d, action, ...confirmation }));
    form.post('/admin/features/groups' + (group ? '/' + group.id : ''), {
      preserveScroll: true,
    });
  };
  return (
    <form
      className="space-y-3 rounded-lg border border-slate-200 bg-white p-5"
      onSubmit={(e) => {
        e.preventDefault();
        submit('save');
      }}
    >
      <h2 className="font-semibold">
        {group?.name || t('features.add_group')}
      </h2>
      <FormErrors errors={form.errors} />
      <AdminTabs
        language={
          <ContentLanguageSelector
            languages={i18n.locales}
            value={locale}
            onChange={setLocale}
          />
        }
      />
      <label className="block">
        {t('features.name')}
        <input
          className="field mt-1"
          value={form.data.translations[locale].name}
          onChange={(e) =>
            form.setData('translations', {
              ...form.data.translations,
              [locale]: {
                ...form.data.translations[locale],
                name: e.target.value,
              },
            })
          }
        />
      </label>
      <div>
        <label
          className="mb-2 block"
          htmlFor={'group-description-' + (group?.id ?? 'new') + '-' + locale}
        >
          {t('features.description')}
        </label>
        <RichText
          key={locale}
          id={'group-description-' + (group?.id ?? 'new') + '-' + locale}
          label={t('features.description')}
          value={form.data.translations[locale].description}
          maxLength={10000}
          disabled={form.processing}
          onChange={(html) =>
            form.setData('translations', {
              ...form.data.translations,
              [locale]: {
                ...form.data.translations[locale],
                description: html,
              },
            })
          }
        />
      </div>
      <div className="flex gap-2">
        <EditorActionButton disabled={form.processing}>
          {t('common.save')}
        </EditorActionButton>
        {group && (
          <button
            type="button"
            className="button-secondary"
            disabled={form.processing}
            onClick={() => submit('delete')}
          >
            {t('features.delete')}
          </button>
        )}
      </div>
    </form>
  );
}
