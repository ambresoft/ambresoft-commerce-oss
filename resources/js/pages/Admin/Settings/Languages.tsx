import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useForm } from '@inertiajs/react';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import Select from '../../../components/admin/Select';

type Language = {
  code: string;
  name: string;
  active: boolean;
  default: boolean;
};
export default function Languages({
  languages,
  ...shell
}: AdminShell & { languages: Language[] }) {
  const t = useTranslation();
  const form = useForm({
    active: languages.filter((l) => l.active).map((l) => l.code),
    default: languages.find((l) => l.default)?.code ?? 'en',
  });
  useDirtyForm(form);
  return (
    <AdminLayout title={t('languages.title')} {...shell}>
      <EditorHeader
        heading={t('languages.title')}
        iconKey="language"
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-settings-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        className="max-w-3xl space-y-6 rounded-lg border border-slate-200 bg-white p-6"
        onSubmit={(e) => {
          e.preventDefault();
          form.put('/admin/settings/languages');
        }}
        id="admin-settings-form-0"
      >
        <p className="text-sm text-slate-600">{t('languages.help')}</p>
        <FormErrors errors={form.errors} />
        <fieldset className="space-y-3">
          <legend className="mb-3 font-medium">{t('languages.active')}</legend>
          {languages.map((l) => (
            <label className="flex items-center gap-3" key={l.code}>
              <input
                type="checkbox"
                checked={form.data.active.includes(l.code)}
                onChange={(e) => {
                  const active = e.target.checked
                    ? [...form.data.active, l.code]
                    : form.data.active.filter((c) => c !== l.code);
                  form.setData({
                    ...form.data,
                    active,
                    default: active.includes(form.data.default)
                      ? form.data.default
                      : (active[0] ?? ''),
                  });
                }}
              />
              {l.name}
              <span className="text-xs text-slate-500">{l.code}</span>
            </label>
          ))}
        </fieldset>
        <div>
          <label htmlFor="system-default" className="mb-2 block font-medium">
            {t('languages.default')}
          </label>
          <Select
            id="system-default"
            value={form.data.default}
            onChange={(value) => form.setData('default', value)}
            options={languages
              .filter((l) => form.data.active.includes(l.code))
              .map((l) => ({ value: l.code, label: l.name }))}
          />
        </div>
        <p className="text-sm text-amber-800">{t('languages.strict_hint')}</p>
      </form>
    </AdminLayout>
  );
}
