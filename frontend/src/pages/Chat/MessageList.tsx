import { useState, type RefObject } from 'react';
import { CheckIcon, ClipboardDocumentIcon, CpuChipIcon, UserIcon } from '@heroicons/react/24/outline';
import { EmptyState, Spinner, cx } from '../../components/ui';
import { Markdown } from './Markdown';
import type { Msg } from './shared';

/** The scrolling message area: loading spinner, empty state, or the conversation's bubbles. */
export function MessageList({ scrollRef, loading, messages, hasModels }: {
  scrollRef: RefObject<HTMLDivElement | null>;
  loading: boolean;
  messages: Msg[];
  hasModels: boolean;
}) {
  const [copied, setCopied] = useState<number | null>(null);

function copyMsg(i: number, text: string) {
  void navigator.clipboard?.writeText(text).then(() => { setCopied(i); setTimeout(() => setCopied(null), 1500); });
}

  return (
    <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-5 sm:px-6" aria-live="polite">
      {loading ? (
        <div className="flex h-full items-center justify-center"><Spinner className="size-6 text-slate-400" /></div>
      ) : !messages.length ? (
        <div className="flex h-full items-center justify-center">
          <EmptyState
            icon={CpuChipIcon}
            title="Start a conversation"
            description={hasModels ? 'Ask your self-hosted model anything. Enter sends, Shift+Enter adds a line.' : 'No models are available yet. Pull or sync one on the Models page first.'}
          />
        </div>
      ) : (
        <div className="mx-auto max-w-3xl space-y-5">
          {messages.map((m, i) => (
            <div key={i} className={cx('flex gap-3', m.role === 'user' && 'flex-row-reverse')}>
              <span className={cx('flex size-8 shrink-0 items-center justify-center rounded-lg', m.role === 'user' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300')}>
                {m.role === 'user' ? <UserIcon aria-hidden className="size-4" /> : <CpuChipIcon aria-hidden className="size-4" />}
              </span>
              <div className={cx('min-w-0', m.role === 'user' ? 'max-w-[85%]' : 'flex-1')}>
                <div className={cx(
                  'rounded-2xl px-4 py-2.5',
                  m.role === 'user'
                    ? 'bg-brand-600 text-white'
                    : m.error
                      ? 'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20'
                      : 'bg-slate-50 dark:bg-white/5',
                )}>
                  {m.images?.length ? (
                    <div className="mb-2 flex flex-wrap gap-2">
                      {m.images.map((src, j) => <img key={j} src={src} alt="Attachment" className="max-h-40 rounded-lg ring-1 ring-white/20" />)}
                    </div>
                  ) : null}
                  {m.role === 'user' ? (
                    <p className="text-sm/6 break-words whitespace-pre-wrap">{m.content}</p>
                  ) : m.error ? (
                    <p className="text-sm">{m.content}</p>
                  ) : m.content ? (
                    <>
                      <Markdown text={m.content} />
                      {m.pending && <span aria-hidden className="ml-0.5 inline-block h-4 w-1.5 animate-pulse bg-slate-400 align-middle dark:bg-slate-500" />}
                    </>
                  ) : (
                    <Spinner className="size-4 text-slate-400" />
                  )}
                </div>
                {m.role === 'assistant' && !m.pending && m.content && !m.error && (
                  <div className="mt-1 flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                    <button type="button" onClick={() => copyMsg(i, m.content)} className="inline-flex items-center gap-1 rounded px-1 py-0.5 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white">
                      {copied === i ? <CheckIcon aria-hidden className="size-3.5 text-emerald-500" /> : <ClipboardDocumentIcon aria-hidden className="size-3.5" />}
                      {copied === i ? 'Copied' : 'Copy'}
                    </button>
                    {m.completion_tokens ? <span>{m.completion_tokens} tokens</span> : null}
                    {m.total_ms ? <span>· {(Number(m.total_ms) / 1000).toFixed(1)} s</span> : null}
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
