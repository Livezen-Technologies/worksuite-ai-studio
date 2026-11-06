import type { UseQueryResult } from '@tanstack/react-query';
import { ChatBubbleLeftRightIcon, PencilSquareIcon, PlusIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Button, ErrorState, IconButton, Skeleton, cx } from '../../components/ui';
import { ago } from '../../lib/format';
import type { Session } from './shared';

/** The conversations column (desktop) / drawer body (phones). */
export function ConversationList({ sessions, sessionId, onNewChat, onClose, onOpen, onRename, onDelete }: {
  sessions: UseQueryResult<Session[]>;
  sessionId: number | null;
  onNewChat: () => void;
  onClose: () => void;
  onOpen: (id: number) => void;
  onRename: (s: Session) => void;
  onDelete: (s: Session) => void;
}) {
  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2 border-b border-slate-200 p-3 dark:border-white/10">
        <Button variant="primary" icon={PlusIcon} className="flex-1" onClick={onNewChat}>New chat</Button>
        <IconButton label="Close conversations" icon={XMarkIcon} className="md:hidden" onClick={onClose} />
      </div>
      <div className="flex-1 overflow-y-auto p-2">
        {sessions.isLoading ? (
          <div className="space-y-2 p-1">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
        ) : sessions.error ? (
          <ErrorState error={sessions.error} onRetry={() => void sessions.refetch()} />
        ) : !sessions.data?.length ? (
          <p className="px-2 py-8 text-center text-xs text-slate-500 dark:text-slate-400">No conversations yet. Your chats appear here.</p>
        ) : (
          <ul className="space-y-0.5">
            {sessions.data.map((s) => (
              <li key={s.id}>
                <div
                  role="button"
                  tabIndex={0}
                  onClick={() => onOpen(s.id)}
                  onKeyDown={(e) => { if (e.key === 'Enter') onOpen(s.id); }}
                  className={cx(
                    'group flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-sm focus-visible:outline-2 focus-visible:outline-brand-600',
                    s.id === sessionId ? 'bg-brand-50 text-brand-700 dark:bg-blue-500/15 dark:text-blue-200' : 'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5',
                  )}
                >
                  <ChatBubbleLeftRightIcon aria-hidden className="size-4 shrink-0 opacity-60" />
                  <div className="min-w-0 flex-1">
                    <div className="truncate">{s.title}</div>
                    <div className="text-[11px] text-slate-500 dark:text-slate-400">{ago(s.updated_at)} · {Number(s.message_count)} msgs</div>
                  </div>
                  <div className="flex shrink-0 md:opacity-0 md:group-focus-within:opacity-100 md:group-hover:opacity-100">
                    <IconButton label="Rename" icon={PencilSquareIcon} className="p-1" onClick={(e) => { e.stopPropagation(); onRename(s); }} />
                    <IconButton label="Delete" icon={TrashIcon} className="p-1 hover:text-red-600 dark:hover:text-red-400" onClick={(e) => { e.stopPropagation(); onDelete(s); }} />
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
