import EditorHeader from '../../../components/admin/EditorHeader';

import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';

type Call = {
  id: string;
  feature: string;
  user: string | null;
  object_type: string;
  object_id: string | null;
  model: string;
  status: string;
  input_tokens: number | null;
  output_tokens: number | null;
  http_status: number | null;
  error_code: string | null;
  duration_ms: number | null;
  created_at: string;
};
export default function AiLog({
  logs,
  ...shell
}: AdminShell & { logs: Page<Call> }) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  return (
    <AdminLayout title={t('ai.log')} {...shell}>
      <EditorHeader
        heading={t('ai.log')}
        iconKey="ai"
        backHref="/admin/settings/general?tab=ai"
        backLabel={t('ai.settings')}
      />
      <p className="mb-5 text-sm text-slate-500">{t('ai.log_help')}</p>
      <section className="list-card">
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('ai.time')}</th>
                <th>{t('ai.user')}</th>
                <th>{t('ai.feature')}</th>
                <th>{t('ai.object')}</th>
                <th>{t('ai.model')}</th>
                <th>{t('common.status')}</th>
                <th>{t('ai.tokens')}</th>
                <th>{t('ai.duration')}</th>
              </tr>
            </thead>
            <tbody>
              {logs.data.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-8 text-center text-slate-500">
                    {t('common.no_results')}
                  </td>
                </tr>
              ) : (
                logs.data.map((call) => {
                  const stamp = new Date(call.created_at);
                  return (
                    <tr key={call.id}>
                      <td>
                        {date.format(stamp)}
                        <span className="block text-xs text-slate-500">
                          {time.format(stamp)}
                        </span>
                      </td>
                      <td>{call.user ?? '—'}</td>
                      <td>{t(`ai.feature_${call.feature}`) || call.feature}</td>
                      <td>
                        {t(`ai.source_${call.object_type}`)}
                        {call.object_id && ' #' + call.object_id}
                      </td>
                      <td className="text-xs">{call.model}</td>
                      <td>
                        <StatusBadge
                          tone={
                            call.status === 'success'
                              ? 'success'
                              : call.status === 'error'
                                ? 'danger'
                                : 'neutral'
                          }
                        >
                          {t(`ai.status_${call.status}`)}
                        </StatusBadge>
                        {call.error_code && (
                          <span className="mt-1 block max-w-xs text-xs text-slate-500">
                            {t(`ai.error_${call.error_code}`)}
                          </span>
                        )}
                        {call.http_status && (
                          <span className="block text-xs text-slate-500">
                            HTTP {call.http_status}
                          </span>
                        )}
                      </td>
                      <td className="tabular-nums">
                        {call.input_tokens ?? '—'} / {call.output_tokens ?? '—'}
                      </td>
                      <td className="tabular-nums">
                        {call.duration_ms ?? '—'}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        <Pagination page={logs} />
      </section>
    </AdminLayout>
  );
}
