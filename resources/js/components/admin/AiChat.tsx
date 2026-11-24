import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import HelpPopover from './HelpPopover';
import { faqRequest } from '../../lib/faqRequest';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';

type Message<Draft> = {
  role: 'user' | 'assistant';
  content: string;
  /** The editor state this reply replaced, so it can be undone. */
  before?: Draft;
  undone?: boolean;
};

/**
 * Shared AI assistant chat for editors (banners, FAQs, blog posts). The server checks every reply with the
 * entity's own rules and returns `{reply, draft}`; the draft is applied to the editor at once and the merchant
 * still saves as usual. Each feature supplies its endpoint, how to read and apply its draft and its own copy.
 */
export default function AiChat<Draft>({
  endpoint,
  payload = {},
  ready,
  current,
  onApply,
  preview,
  label,
  intro,
  placeholder,
  consent,
  notConfigured,
  examples = [],
}: {
  endpoint: string;
  /** Extra request fields, e.g. the record ID or the content language. */
  payload?: Record<string, unknown>;
  ready: boolean;
  current: () => Draft;
  onApply: (draft: Draft) => void;
  /** Shown beside the chat so each reply is visible at once. */
  preview?: ReactNode;
  label: string;
  intro: string;
  placeholder: string;
  consent: string;
  notConfigured: string;
  /** Example requests shown in a help tooltip next to the introduction. */
  examples?: string[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const [messages, setMessages] = useState<Message<Draft>[]>([]);
  const [text, setText] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState('');
  const log = useRef<HTMLOListElement>(null);
  const inputId = useId();
  useEffect(() => {
    log.current?.scrollTo?.({ top: log.current.scrollHeight });
  }, [messages, processing]);

  const send = async () => {
    const request = text.trim();
    if (!request || processing) return;
    if (!ready) {
      setError(notConfigured);
      return;
    }
    const history: Message<Draft>[] = [
      ...messages,
      { role: 'user', content: request },
    ];
    const before = current();
    setMessages(history);
    setText('');
    setError('');
    setProcessing(true);
    try {
      const response = await faqRequest<{ reply: string; draft: Draft }>(
        endpoint,
        {
          ...payload,
          messages: history.map(({ role, content }) => ({ role, content })),
          current: before,
        },
      );
      onApply(response.draft);
      setMessages([
        ...history,
        { role: 'assistant', content: response.reply, before },
      ]);
    } catch (failure) {
      // The unanswered request goes back into the box so nothing typed is lost.
      setMessages(messages);
      setText(request);
      const message = failure instanceof Error ? failure.message : '';
      setError(
        message === 'rate_limited'
          ? t('ai.chat_rate_limited')
          : message === 'request_failed' || !message
            ? t('ai.chat_connection_error')
            : message,
      );
    } finally {
      setProcessing(false);
    }
  };

  const undo = (index: number) => {
    const before = messages[index].before;
    if (!before) return;
    onApply(before);
    setMessages(
      messages.map((message, i) =>
        i === index ? { ...message, undone: true } : message,
      ),
    );
  };

  return (
    <div
      className={
        preview ? 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' : ''
      }
    >
      <section
        aria-label={label}
        className="flex min-h-[28rem] flex-col rounded-lg border border-slate-200 bg-white"
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-3">
          <div className="flex items-start gap-2">
            <p className="text-sm text-slate-600">{intro}</p>
            {examples.length > 0 && (
              <HelpPopover label={t('ai.chat_examples')}>
                <span className="block font-medium">
                  {t('ai.chat_examples')}
                </span>
                <span className="mt-1 block space-y-2">
                  {examples.map((example) => (
                    <span key={example} className="block">
                      “{example}”
                    </span>
                  ))}
                </span>
              </HelpPopover>
            )}
          </div>
          {messages.length > 0 && (
            <button
              type="button"
              className="button-secondary shrink-0"
              disabled={processing}
              onClick={() => {
                setMessages([]);
                setError('');
              }}
            >
              {t('ai.chat_new')}
            </button>
          )}
        </div>
        <ol
          ref={log}
          aria-live="polite"
          className="flex max-h-[32rem] flex-1 flex-col gap-3 overflow-y-auto px-5 py-4"
        >
          {messages.map((message, index) => (
            <li
              key={index}
              className={
                message.role === 'user'
                  ? 'ml-8 self-end rounded-lg bg-primary-tint px-3 py-2 text-sm'
                  : 'mr-8 self-start rounded-lg bg-slate-100 px-3 py-2 text-sm'
              }
            >
              <span className="sr-only">
                {t(
                  message.role === 'user' ? 'ai.chat_you' : 'ai.chat_assistant',
                )}
                :{' '}
              </span>
              <p className="whitespace-pre-line">{message.content}</p>
              {message.before && (
                <p className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                  {t('ai.chat_applied')}
                  {!message.undone && index === messages.length - 1 && (
                    <button
                      type="button"
                      className="font-medium text-primary underline"
                      onClick={() => undo(index)}
                    >
                      {t('ai.chat_undo')}
                    </button>
                  )}
                </p>
              )}
            </li>
          ))}
          {processing && (
            <li className="mr-8 self-start rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-500">
              {t('ai.chat_thinking')}
            </li>
          )}
        </ol>
        <div className="border-t border-slate-200 px-5 py-4">
          {!ready && (
            <p className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
              {notConfigured}{' '}
              {capabilities['settings.manage'] && (
                <Link
                  href="/admin/settings/general?tab=ai"
                  className="font-medium underline"
                >
                  {t('ai.settings')}
                </Link>
              )}
            </p>
          )}
          {error && (
            <p role="alert" className="mb-3 text-sm text-red-700">
              {error}
            </p>
          )}
          <label htmlFor={inputId} className="sr-only">
            {placeholder}
          </label>
          <textarea
            id={inputId}
            className="field min-h-20"
            rows={3}
            maxLength={4000}
            value={text}
            placeholder={placeholder}
            disabled={processing || !ready}
            onChange={(event) => setText(event.target.value)}
            onKeyDown={(event) => {
              if (
                event.key === 'Enter' &&
                !event.shiftKey &&
                !event.nativeEvent.isComposing
              ) {
                event.preventDefault();
                void send();
              }
            }}
          />
          <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-slate-500">
              {t('ai.chat_shortcut')} {consent}
            </p>
            <button
              type="button"
              className="button-primary"
              disabled={processing || !ready || !text.trim()}
              onClick={() => void send()}
            >
              {t('ai.chat_send')}
            </button>
          </div>
        </div>
      </section>
      {preview && (
        <div className="xl:sticky xl:top-4 xl:self-start">{preview}</div>
      )}
    </div>
  );
}
