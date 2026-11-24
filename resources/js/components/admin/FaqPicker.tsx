import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Select from './Select';
import FaqTextFields from './FaqTextFields';
import FaqAiModal from './FaqAiModal';
import { SearchField } from './list/FilterBar';
import { useTranslation } from '../../lib/useTranslation';
import { faqRequest } from '../../lib/faqRequest';
import type { FaqDraft, FaqOption, FaqSource } from '../../types/faqs';

export default function FaqPicker({
  ids,
  drafts,
  options,
  source,
  onIds,
  onDrafts,
  errors = {},
  disabled = false,
  locale,
}: {
  ids: string[];
  drafts: FaqDraft[];
  options: FaqOption[];
  source: FaqSource;
  onIds: (ids: string[]) => void;
  onDrafts: (drafts: FaqDraft[]) => void;
  errors?: Record<string, string>;
  disabled?: boolean;
  locale?: string;
}) {
  const t = useTranslation();
  const [search, setSearch] = useState('');
  const [remote, setRemote] = useState<FaqOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const all = Array.from(
    new Map(
      [...options, ...remote].map((option) => [option.id, option]),
    ).values(),
  );
  const searchOptions = async () => {
    setLoading(true);
    setError('');
    const query = new URLSearchParams({ owner: source.owner, search });
    ids.forEach((id) => query.append('selected[]', id));
    try {
      setRemote(await faqRequest<FaqOption[]>('/admin/faqs/options?' + query));
    } catch {
      setError(t('faqs.search_failed'));
    } finally {
      setLoading(false);
    }
  };
  const move = <T,>(values: T[], index: number, step: number) => {
    const next = [...values];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    return next;
  };
  const choices = (search ? remote : all).filter(
    (option) => option.status === 'active' && !ids.includes(option.id),
  );
  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold">{t('faqs.title')}</h2>
          <p className="mt-1 text-xs leading-5 text-slate-500">
            {t('faqs.link_help')}
          </p>
        </div>
        <FaqAiModal
          source={source}
          disabled={disabled || drafts.length >= 10}
          maxEntries={10 - drafts.length}
          onAdd={(next) => onDrafts([...drafts, ...next])}
        />
      </div>
      {!disabled && (
        <div className="space-y-3">
          <SearchField
            embedded
            id={'faq-search-' + source.owner}
            value={search}
            onChange={setSearch}
            onSubmit={() => void searchOptions()}
            placeholder={t('faqs.search')}
          />
          <label htmlFor="faq-add" className="block text-sm font-medium">
            {t('faqs.link_add')}
          </label>
          <Select
            id="faq-add"
            value=""
            disabled={disabled || loading || ids.length + drafts.length >= 100}
            onChange={(id) => {
              if (id) onIds([...ids, id]);
            }}
            options={[
              { value: '', label: t(loading ? 'common.wait' : 'faqs.choose') },
              ...choices.map((option) => ({
                value: option.id,
                label: option.question + ' · ' + t(`faqs.type_${option.type}`),
              })),
            ]}
          />
          <p className="text-xs text-slate-500">{t('faqs.search_help')}</p>
        </div>
      )}
      {(error || errors.faq_ids) && (
        <p role="alert" className="text-sm text-red-700">
          {error || errors.faq_ids}
        </p>
      )}
      {ids.length === 0 && drafts.length === 0 && (
        <p className="text-sm text-slate-500">{t('faqs.none_linked')}</p>
      )}
      <ol className="divide-y divide-slate-200">
        {ids.map((id, index) => {
          const faq = all.find((option) => option.id === id);
          return (
            <li
              key={id}
              className="flex flex-wrap items-center justify-between gap-3 py-3"
            >
              <div className="min-w-0">
                <Link
                  className="text-link text-sm"
                  href={'/admin/faqs/' + id + '/edit'}
                >
                  {index + 1}. {faq?.question ?? '#' + id}
                </Link>
                {faq?.status === 'disabled' && (
                  <span className="ml-2 text-xs text-slate-500">
                    {t('common.inactive')}
                  </span>
                )}
              </div>
              {!disabled && (
                <div className="flex gap-2">
                  <button
                    type="button"
                    className="button-secondary"
                    aria-label={t('faqs.move_up', {
                      question: faq?.question ?? id,
                    })}
                    disabled={index === 0}
                    onClick={() => onIds(move(ids, index, -1))}
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    className="button-secondary"
                    aria-label={t('faqs.move_down', {
                      question: faq?.question ?? id,
                    })}
                    disabled={index === ids.length - 1}
                    onClick={() => onIds(move(ids, index, 1))}
                  >
                    ↓
                  </button>
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() => onIds(ids.filter((value) => value !== id))}
                  >
                    {t('faqs.unlink')}
                  </button>
                </div>
              )}
            </li>
          );
        })}
      </ol>
      {drafts.map((draft, index) => (
        <section
          key={index}
          className="space-y-4 border-t border-slate-200 pt-5"
        >
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h3 className="text-sm font-semibold">
              {t('faqs.draft_number', { number: index + 1 })}
            </h3>
            <div className="flex gap-2">
              <button
                type="button"
                className="button-secondary"
                disabled={disabled || index === 0}
                onClick={() => onDrafts(move(drafts, index, -1))}
              >
                ↑
              </button>
              <button
                type="button"
                className="button-secondary"
                disabled={disabled || index === drafts.length - 1}
                onClick={() => onDrafts(move(drafts, index, 1))}
              >
                ↓
              </button>
              <button
                type="button"
                className="button-secondary"
                disabled={disabled}
                onClick={() => onDrafts(drafts.filter((_, i) => i !== index))}
              >
                {t('common.delete')}
              </button>
            </div>
          </div>
          <FaqTextFields
            locale={locale}
            prefix={'faq_drafts.' + index + '.translations'}
            translations={draft.translations}
            errors={errors}
            disabled={disabled}
            onChange={(translations) =>
              onDrafts(
                drafts.map((row, i) => (i === index ? { translations } : row)),
              )
            }
          />
        </section>
      ))}
    </section>
  );
}
