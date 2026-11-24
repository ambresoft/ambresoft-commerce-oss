import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { AccountPageData } from '../../types';
import { useDateFormat } from './AccountParts';

/** Account → Newsletters: the lists the customer is on, each with a way to leave it. */
export default function AccountNewsletters({
  data,
}: {
  data: AccountPageData;
}) {
  const t = useTranslation();
  const format = useDateFormat();
  const [leaving, setLeaving] = useState<string | null>(null);
  const lists = data.newsletters ?? [];
  return (
    <section className="sf-acc-card">
      <h2>{t('account.title_newsletters')}</h2>
      {lists.length === 0 ? (
        <p className="sf-acc-empty">{t('account.newsletters_empty')}</p>
      ) : (
        <ul className="sf-acc-rows">
          {lists.map((list) => (
            <li key={list.id}>
              <div>
                <strong>{list.name}</strong>
                <div className="sf-acc-review-meta">
                  <span>
                    {t('account.newsletter_since', {
                      date: format(list.since),
                    })}
                  </span>
                  {!list.confirmed && (
                    <span className="sf-acc-state is-pending">
                      {t('account.newsletter_unconfirmed')}
                    </span>
                  )}
                </div>
              </div>
              <button
                type="button"
                className="sf-acc-secondary"
                disabled={leaving !== null}
                aria-label={t('account.newsletter_leave_named', {
                  name: list.name,
                })}
                onClick={() => {
                  setLeaving(list.id);
                  router.delete(`/account/newsletters/${list.id}`, {
                    preserveScroll: true,
                    onFinish: () => setLeaving(null),
                  });
                }}
              >
                {t('account.newsletter_leave')}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
