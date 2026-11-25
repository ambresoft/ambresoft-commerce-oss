import EditorHeader from '../../../components/admin/EditorHeader';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import {
  Check,
  Choice,
  Row,
  Section,
} from '../../../components/admin/form/fields';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { SeoRule } from '../../../types/seoRules';

type Props = AdminShell & { rule: SeoRule | null };

export default function SeoRuleForm(props: Props) {
  // A fresh editor after every save so the form mirrors the stored rule.
  return (
    <Editor
      key={(props.rule?.id ?? 'new') + ':' + (props.rule?.version ?? 0)}
      {...props}
    />
  );
}

function Editor({ rule, ...shell }: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const allowed = !!capabilities['seo-rules.manage'];
  const [removing, setRemoving] = useState(false);
  const form = useForm({
    version: rule?.version ?? null,
    source_path: rule?.source_path ?? '',
    destination: rule?.destination ?? '',
    status_code: String(rule?.status_code ?? 301),
    enabled: rule?.enabled ?? true,
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const title = t(rule ? 'seo_rules.edit' : 'seo_rules.create');
  const submit = () => {
    if (rule) form.put('/admin/seo-rules/' + rule.id, { preserveScroll: true });
    else form.post('/admin/seo-rules', { preserveScroll: true });
  };
  const field = (name: 'source_path' | 'destination', placeholder: string) => (
    <input
      id={name}
      className="field font-mono"
      maxLength={2048}
      placeholder={placeholder}
      disabled={!allowed}
      value={form.data[name]}
      aria-invalid={!!errors[name]}
      onChange={(e) => form.setData(name, e.target.value)}
    />
  );

  return (
    <AdminLayout title={title} {...shell}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (allowed) submit();
        }}
        id="admin-seorules-form-0"
      >
        <EditorHeader
          heading={title}
          iconKey="seo"
          backHref="/admin/seo-rules"
          backLabel={t('seo_rules.back')}
          description={
            <>
              {!allowed && (
                <p className="mt-2 text-sm text-slate-500">
                  {t('seo_rules.read_only')}
                </p>
              )}
            </>
          }
          actions={
            <>
              <div className="flex flex-wrap gap-2">
                {allowed && (
                  <button
                    className="button-primary"
                    disabled={form.processing}
                    form="admin-seorules-form-0"
                  >
                    {t('seo_rules.save')}
                  </button>
                )}
              </div>
            </>
          }
          menu={[
            rule && allowed && (
              <button
                type="button"
                className="button-secondary text-red-700"
                onClick={() => setRemoving(true)}
              >
                {t('seo_rules.delete')}
              </button>
            ),
          ]}
        />

        {errors.version && (
          <p
            role="alert"
            className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"
          >
            {errors.version}
          </p>
        )}
        {rule?.enabled && (rule.chain_to || rule.incoming.length > 0) && (
          <div
            role="note"
            className="mb-4 space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
          >
            {rule.chain_to && (
              <p>{t('seo_rules.chain_warning', { next: rule.chain_to })}</p>
            )}
            {rule.incoming.length > 0 && (
              <p>
                {t('seo_rules.shadowed_warning', {
                  sources: rule.incoming.join(', '),
                })}
              </p>
            )}
          </div>
        )}

        <Section title={title} iconKey="seo">
          <Row
            id="source_path"
            label={t('seo_rules.source_path')}
            help={t('seo_rules.source_help')}
            required
            error={errors.source_path}
          >
            {field('source_path', '/old-page')}
          </Row>
          <Row
            id="destination"
            label={t('seo_rules.destination')}
            help={t('seo_rules.destination_help')}
            required
            error={errors.destination}
          >
            {field('destination', '/new-page')}
          </Row>
          <Row
            id="status_code"
            label={t('seo_rules.status_code')}
            help={t('seo_rules.code_help')}
            required
            error={errors.status_code}
          >
            <Choice
              id="status_code"
              className="max-w-xs"
              value={form.data.status_code}
              disabled={!allowed}
              options={[
                { value: '301', label: t('seo_rules.code_301') },
                { value: '302', label: t('seo_rules.code_302') },
              ]}
              onChange={(value) => form.setData('status_code', value)}
            />
          </Row>
          <Row
            id="enabled"
            label={t('seo_rules.enabled')}
            help={t('seo_rules.enabled_help')}
            error={errors.enabled}
          >
            <Check
              id="enabled"
              checked={form.data.enabled}
              disabled={!allowed}
              onChange={(on) => form.setData('enabled', on)}
            />
          </Row>
        </Section>
      </form>
      {removing && rule && (
        <ConfirmDeleteDialog
          title={t('seo_rules.delete_title', { source: rule.source_path })}
          facts={[t('seo_rules.delete_body')]}
          onCancel={() => setRemoving(false)}
          onConfirm={() =>
            router.delete('/admin/seo-rules/' + rule.id, {
              data: { version: rule.version },
              onFinish: () => setRemoving(false),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
