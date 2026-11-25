import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { useEffect, useRef, useState } from 'react';
import IconButton from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import { Icon } from '../../../components/admin/list/IconButton';
import type {
  BlockConfig,
  BlockDefinition,
  Placement,
} from '../../../types/layouts';

export default function AddBlockDialog({
  definitions,
  sources,
  onCreate,
  onExisting,
  onClose,
}: {
  definitions: Record<string, BlockDefinition>;
  sources: Placement[];
  onCreate: (type: string, template: string) => void;
  onExisting: (block: BlockConfig) => void;
  onClose: () => void;
}) {
  const t = useTranslation();
  const ref = useRef<HTMLDialogElement>(null);
  const [tab, setTab] = useState<'new' | 'existing'>('new');
  const [search, setSearch] = useState('');
  useEffect(() => {
    ref.current?.showModal();
  }, []);
  return (
    <dialog
      ref={ref}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      aria-labelledby="add-block-title"
      className="m-auto max-h-[85vh] w-[min(52rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 text-slate-900 backdrop:bg-black/50"
    >
      <div className="mb-4 flex items-center justify-between">
        <h2 id="add-block-title" className="text-lg font-semibold">
          {t('layouts.add_block')}
        </h2>
        <IconButton icon="x" label={t('common.cancel')} onClick={onClose} />
      </div>
      <AdminTabs label={t('layouts.add_block')} className="mb-5">
        {(['new', 'existing'] as const).map((key) => (
          <AdminTab
            type="button"
            role="tab"
            aria-selected={tab === key}
            key={key}
            onClick={() => setTab(key)}
            iconKey={key}
          >
            {t(key === 'new' ? 'layouts.create_new' : 'layouts.use_existing')}
          </AdminTab>
        ))}
      </AdminTabs>
      {tab === 'new' ? (
        <>
          <p className="mb-4 text-sm text-slate-500">{t('layouts.add_help')}</p>
          <div className="grid gap-3 sm:grid-cols-2">
            {Object.entries(definitions).map(([id, d]) => (
              <section
                key={id}
                className="rounded-lg border border-slate-200 p-4"
              >
                <h3 className="mb-3 flex items-center gap-2 font-medium">
                  <Icon name={id === 'html' ? 'file' : 'box'} />
                  {t(d.label)}
                </h3>
                <div className="flex flex-wrap gap-2">
                  {d.templates.map((template) => (
                    <button
                      key={template}
                      type="button"
                      className="button-secondary text-sm"
                      onClick={() => onCreate(id, template)}
                    >
                      {t(
                        d.template_labels?.[template] ??
                          `layouts.template_${template}`,
                      )}
                    </button>
                  ))}
                </div>
              </section>
            ))}
          </div>
        </>
      ) : (
        <div>
          <label className="mb-4 block text-sm">
            {t('layouts.find_block')}
            <input
              className="field mt-2 w-full"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </label>
          <p className="mb-3 text-sm text-slate-500">
            {t('layouts.copy_help')}
          </p>
          <div className="grid gap-2 sm:grid-cols-2">
            {sources
              .filter((p) =>
                p.block.name
                  .toLocaleLowerCase()
                  .includes(search.toLocaleLowerCase()),
              )
              .map((p) => (
                <button
                  type="button"
                  key={p.id}
                  className="button-secondary justify-start"
                  onClick={() => onExisting(p.block)}
                >
                  {p.block.name}
                </button>
              ))}
          </div>
        </div>
      )}
    </dialog>
  );
}
