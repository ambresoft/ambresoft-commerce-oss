import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';

type Transfer = {
  id: string;
  kind: 'import' | 'export';
  title: string;
  description: string;
  href: string;
};

function Direction({ kind }: { kind: Transfer['kind'] }) {
  return (
    <span
      aria-hidden="true"
      className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-primary"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="size-5"
      >
        <path d="M14 3H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7l-4-4Z" />
        <path d="M14 3v4h4" />
        {kind === 'import' ? (
          <path d="M12 10v7m0 0-2.5-2.5M12 17l2.5-2.5" />
        ) : (
          <path d="M12 17v-7m0 0-2.5 2.5M12 10l2.5 2.5" />
        )}
      </svg>
    </span>
  );
}

function Section({
  id,
  title,
  empty,
  transfers,
}: {
  id: string;
  title: string;
  empty: string;
  transfers: Transfer[];
}) {
  const t = useTranslation();
  return (
    <section>
      <AdminCard
        id={id}
        title={title}
        iconKey="core.data-transfer"
        bodyClassName="p-0 "
      >
        {transfers.length ? (
          <ul className="divide-y divide-slate-100">
            {transfers.map((transfer) => (
              <li
                key={transfer.id}
                className="flex flex-wrap items-center gap-4 px-5 py-4"
              >
                <Direction kind={transfer.kind} />
                <div className="min-w-56 flex-1">
                  <p className="flex items-center gap-2 font-semibold">
                    {transfer.title}
                    <span className="rounded border border-slate-200 px-1.5 py-px text-[10px] font-semibold tracking-wide text-slate-500">
                      {t('admin.data_transfer.csv')}
                    </span>
                  </p>
                  <p className="mt-0.5 text-sm text-slate-500">
                    {transfer.description}
                  </p>
                </div>
                <Link
                  className="button-secondary"
                  href={transfer.href}
                  aria-label={
                    t('admin.data_transfer.open') + ': ' + transfer.title
                  }
                >
                  {t('admin.data_transfer.open')}
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <p className="px-5 py-8 text-center text-sm text-slate-500">
            {empty}
          </p>
        )}
      </AdminCard>
    </section>
  );
}

export default function DataTransferIndex({
  transfers,
  ...shell
}: AdminShell & { transfers: Transfer[] }) {
  const t = useTranslation();
  const title = t('admin.data_transfer.title');
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="core.data-transfer"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('admin.data_transfer.description')}
            </p>
          </>
        }
      />
      <div className="space-y-6">
        <Section
          id="imports-heading"
          title={t('admin.data_transfer.imports')}
          empty={t('admin.data_transfer.no_imports')}
          transfers={transfers.filter((item) => item.kind === 'import')}
        />
        <Section
          id="exports-heading"
          title={t('admin.data_transfer.exports')}
          empty={t('admin.data_transfer.no_exports')}
          transfers={transfers.filter((item) => item.kind === 'export')}
        />
      </div>
    </AdminLayout>
  );
}
