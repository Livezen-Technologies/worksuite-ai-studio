import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { useLocation } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AdjustmentsHorizontalIcon, ArrowDownTrayIcon, ArrowPathIcon, Bars3Icon, BookOpenIcon, ChatBubbleLeftRightIcon,
  CheckIcon, ClipboardDocumentIcon, CpuChipIcon, PaperAirplaneIcon, PaperClipIcon, PencilSquareIcon, PhotoIcon,
  PlusIcon, StopIcon, TrashIcon, UserIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import {
  Badge, Button, ConfirmDialog, EmptyState, ErrorState, FormModal, IconButton, Input, Modal, Select,
  Skeleton, Spinner, Textarea, cx, useToast,
} from '../components/ui';
import { ApiError, del, get, put, streamNdjson } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ago } from '../lib/format';
import { Markdown } from './Chat/Markdown';
import { VoiceInput } from './Speech/VoiceInput';

interface Session { id: number; title: string; model: string | null; server_id: number | null; system_prompt: string | null; updated_at: string; message_count: number | string }
interface Msg {
  id?: number; role: 'user' | 'assistant' | 'system'; content: string; images?: string[]; pending?: boolean; error?: boolean;
  prompt_tokens?: number | null; completion_tokens?: number | null; total_ms?: number | null;
}
interface ModelRow { id: number; name: string; server_id: number; server_name: string; server_status: string; is_default?: number | string | boolean; family?: string | null }
/** Embedding models can't hold a conversation; keep them out of the picker. */
const isChatModel = (m: ModelRow) => !/(embed|bge-|minilm|e5-)/i.test(`${m.name} ${m.family ?? ''}`);
interface Agent { id: number; name: string; avatar: string | null; model: string | null; server_id: number | null; system_prompt: string | null; status: string }
interface Prompt { id: number; name: string; category: string; content: string; variables: string[] }

const VISION = /llava|llama3\.2-vision|llama3\.1-vision|moondream|minicpm-v|bakllava|qwen2(\.5)?-?vl|gemma3|cogvlm|llava-phi|gpt-4o|gpt-4\.1|gpt-4-turbo|chatgpt-4o|^o[134]|claude|gemini/i;
const keyOf = (server_id: number | null, model: string | null | undefined) => `${server_id}::${model ?? ''}`;
const split = (k: string) => { const [s, ...r] = k.split('::'); return { server_id: Number(s), model: r.join('::') }; };

export default function Chat() {
  const qc = useQueryClient();
  const toast = useToast();
  const location = useLocation();
  const { can } = useAuth();

  const [sessionId, setSessionId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [draft, setDraft] = useState('');
  const [images, setImages] = useState<string[]>([]);
  const [modelKey, setModelKey] = useState('');
  const [systemPrompt, setSystemPrompt] = useState('');
  const [streaming, setStreaming] = useState(false);
  const [loadingSession, setLoadingSession] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [promptsOpen, setPromptsOpen] = useState(false);
  const [renaming, setRenaming] = useState<Session | null>(null);
  const [renameTitle, setRenameTitle] = useState('');
  const [deleting, setDeleting] = useState<Session | null>(null);
  const [copied, setCopied] = useState<number | null>(null);

  const abortRef = useRef<AbortController | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const sessions = useQuery({ queryKey: ['chat-sessions'], queryFn: () => get<Session[]>('/chat/sessions') });
  const models = useQuery({ queryKey: ['models'], queryFn: () => get<ModelRow[]>('/models'), enabled: can('models.view') });
  const agents = useQuery({ queryKey: ['agents'], queryFn: () => get<Agent[]>('/agents'), enabled: can('agents.view') });
  const prompts = useQuery({ queryKey: ['prompts'], queryFn: () => get<Prompt[]>('/prompts'), enabled: can('prompts.view') });

  const model = modelKey ? split(modelKey).model : '';
  const isVision = VISION.test(model);
  const current = sessions.data?.find((s) => s.id === sessionId) ?? null;
  const locked = sessionId !== null; // the server fixes the system prompt when the conversation is created

  useEffect(() => {
    const usable = (models.data ?? []).filter(isChatModel);
    const pick = usable.find((m) => Number(m.is_default) === 1) ?? usable[0];
    if (!modelKey && pick) setModelKey(keyOf(pick.server_id, pick.name));
  }, [models.data, modelKey]);

  // Arrive from Prompts ("Use in chat") or Agents ("Chat with agent").
  useEffect(() => {
    const st = location.state as { prompt?: string; agent?: Agent } | null;
    if (st?.prompt) setDraft(st.prompt);
    if (st?.agent) applyAgent(st.agent);
    if (st) window.history.replaceState({}, '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [messages]);

  useEffect(() => () => abortRef.current?.abort(), []);

  const refreshSessions = () => qc.invalidateQueries({ queryKey: ['chat-sessions'] });

  function newChat() {
    abortRef.current?.abort();
    setStreaming(false);
    setSessionId(null);
    setMessages([]);
    setDraft('');
    setImages([]);
    setSystemPrompt('');
    setSidebarOpen(false);
    inputRef.current?.focus();
  }

  function applyAgent(a: Agent) {
    newChat();
    if (a.model && a.server_id) setModelKey(keyOf(a.server_id, a.model));
    setSystemPrompt(a.system_prompt ?? '');
    toast.info(`Chatting as ${a.name}`, "The agent's model and system prompt are set for this new conversation.");
  }

  async function openSession(id: number) {
    abortRef.current?.abort();
    setStreaming(false);
    setSidebarOpen(false);
    setLoadingSession(true);
    try {
      const s = await get<Session & { messages: Msg[] }>(`/chat/sessions/${id}`);
      setSessionId(id);
      setMessages((s.messages ?? []).filter((m) => m.role !== 'system'));
      setSystemPrompt(s.system_prompt ?? '');
      if (s.model && s.server_id) setModelKey(keyOf(s.server_id, s.model));
    } catch (e) {
      toast.error(e);
    } finally {
      setLoadingSession(false);
    }
  }

  const patchLast = (fn: (m: Msg) => Msg) =>
    setMessages((prev) => {
      const next = [...prev];
      const last = next[next.length - 1];
      if (last?.role === 'assistant') next[next.length - 1] = fn(last);
      return next;
    });

  async function send(textArg?: string, history?: Msg[]) {
    const text = (textArg ?? draft).trim();
    const imgs = textArg === undefined ? images : [];
    if ((!text && !imgs.length) || !modelKey || streaming) return;
    const { server_id, model: m } = split(modelKey);
    if (textArg === undefined) { setDraft(''); setImages([]); }
    setMessages([...(history ?? messages), { role: 'user', content: text || '(image)', images: imgs.length ? imgs : undefined }, { role: 'assistant', content: '', pending: true }]);
    setStreaming(true);
    const ctl = new AbortController();
    abortRef.current = ctl;
    try {
      await streamNdjson('/chat', {
        session_id: sessionId ?? undefined,
        server_id,
        model: m,
        content: text,
        images: imgs.length ? imgs : undefined,
        system_prompt: sessionId ? undefined : systemPrompt.trim() || undefined,
      }, (line) => {
        if (line.meta && line.session_id) setSessionId(Number(line.session_id));
        if (typeof line.delta === 'string') { const d = line.delta; patchLast((l) => ({ ...l, content: l.content + d })); }
        if (line.error) patchLast((l) => ({ ...l, content: String(line.error), error: true, pending: false }));
        else if (line.done) patchLast((l) => ({
          ...l, pending: false,
          prompt_tokens: (line.prompt_tokens as number | null) ?? null,
          completion_tokens: (line.completion_tokens as number | null) ?? null,
          total_ms: (line.total_ms as number | null) ?? null,
        }));
      }, ctl.signal);
    } catch (e) {
      if (!ctl.signal.aborted) {
        patchLast((l) => ({ ...l, pending: false, error: true, content: l.content || (e instanceof Error ? e.message : 'The reply failed.') }));
        toast.error(e);
      }
    } finally {
      setStreaming(false);
      setMessages((prev) => prev.map((x) => (x.pending ? { ...x, pending: false } : x)));
      void refreshSessions();
    }
  }

  function stop() {
    abortRef.current?.abort();
    setStreaming(false);
    setMessages((prev) => prev.map((x) => ({ ...x, pending: false })));
  }

  /** Re-ask the last question. The server keeps earlier turns, so this adds a fresh turn to the conversation. */
  function regenerate() {
    const idx = messages.map((m) => m.role).lastIndexOf('user');
    if (idx < 0) return;
    const last = messages[idx];
    void send(last.content === '(image)' ? 'Describe this image.' : last.content, messages.slice(0, idx));
  }

  function attach(files: FileList | null) {
    if (!files) return;
    Array.from(files).slice(0, 4).forEach((f) => {
      if (!f.type.startsWith('image/')) return;
      if (f.size > 8 * 1024 * 1024) { toast.error(`${f.name} is over 8 MB`); return; }
      const r = new FileReader();
      r.onload = () => setImages((p) => [...p, String(r.result)].slice(0, 4));
      r.readAsDataURL(f);
    });
    if (fileRef.current) fileRef.current.value = '';
  }

  function exportJson() {
    const data = { session_id: sessionId, title: current?.title ?? null, model, system_prompt: systemPrompt || null, messages: messages.map(({ pending: _p, ...m }) => m) };
    const href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    Object.assign(document.createElement('a'), { href, download: `chat-${sessionId ?? 'new'}.json` }).click();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  }

  function copyMsg(i: number, text: string) {
    void navigator.clipboard?.writeText(text).then(() => { setCopied(i); setTimeout(() => setCopied(null), 1500); });
  }

  const rename = useMutation({
    mutationFn: () => put<Session>(`/chat/sessions/${renaming!.id}`, { title: renameTitle.trim() }),
    onSuccess: () => { toast.success('Conversation renamed'); setRenaming(null); void refreshSessions(); },
    onError: (e) => toast.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => del(`/chat/sessions/${id}`),
    onSuccess: (_d, id) => { toast.success('Conversation deleted'); setDeleting(null); void refreshSessions(); if (id === sessionId) newChat(); },
    onError: (e) => toast.error(e),
  });

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); void send(); }
  };

  const modelOptions = useMemo(
    () => (models.data ?? []).filter(isChatModel)
      .sort((a, b) => Number(b.is_default ?? 0) - Number(a.is_default ?? 0) || a.name.localeCompare(b.name))
      .map((m) => ({ value: keyOf(m.server_id, m.name), label: `${m.name}${Number(m.is_default) === 1 ? ' (default)' : ''} · ${m.server_name}${m.server_status && m.server_status !== 'online' ? ` (${m.server_status})` : ''}` })),
    [models.data],
  );
  // A conversation's model may no longer be listed; keep it selectable.
  if (modelKey && !modelOptions.some((o) => o.value === modelKey)) modelOptions.unshift({ value: modelKey, label: split(modelKey).model });
  const activeAgents = (agents.data ?? []).filter((a) => a.status === 'active');

  const sidebar = (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2 border-b border-slate-200 p-3 dark:border-white/10">
        <Button variant="primary" icon={PlusIcon} className="flex-1" onClick={newChat}>New chat</Button>
        <IconButton label="Close conversations" icon={XMarkIcon} className="md:hidden" onClick={() => setSidebarOpen(false)} />
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
                  onClick={() => void openSession(s.id)}
                  onKeyDown={(e) => { if (e.key === 'Enter') void openSession(s.id); }}
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
                    <IconButton label="Rename" icon={PencilSquareIcon} className="p-1" onClick={(e) => { e.stopPropagation(); setRenaming(s); setRenameTitle(s.title); }} />
                    <IconButton label="Delete" icon={TrashIcon} className="p-1 hover:text-red-600 dark:hover:text-red-400" onClick={(e) => { e.stopPropagation(); setDeleting(s); }} />
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );

  return (
    <div className="flex h-[calc(100dvh-9rem)] min-h-[28rem] gap-4">
      {/* Conversations: a column on desktop, a drawer on phones */}
      <aside className="hidden w-64 shrink-0 overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-slate-900/5 md:block dark:bg-white/[0.03] dark:ring-white/10">{sidebar}</aside>
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-slate-900/50 dark:bg-slate-950/70" onClick={() => setSidebarOpen(false)} />
          <div className="absolute inset-y-0 left-0 w-72 max-w-[85vw] bg-white shadow-xl dark:bg-slate-900">{sidebar}</div>
        </div>
      )}

      <section className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-slate-900/5 dark:bg-white/[0.03] dark:ring-white/10">
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-3 py-2.5 sm:px-4 dark:border-white/10">
          <IconButton label="Conversations" icon={Bars3Icon} className="md:hidden" onClick={() => setSidebarOpen(true)} />
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-sm font-semibold text-slate-900 dark:text-white">{current?.title ?? (sessionId ? 'Conversation' : 'New conversation')}</h1>
            {systemPrompt && <p className="truncate text-xs text-slate-500 dark:text-slate-400">System: {systemPrompt}</p>}
          </div>
          <div className="order-last w-full sm:order-none sm:w-64">
            {models.isLoading ? <Skeleton className="h-9 w-full" /> : (
              <Select
                aria-label="Model"
                value={modelKey}
                onChange={(e) => setModelKey(e.target.value)}
                options={modelOptions}
                placeholder={modelOptions.length ? undefined : 'No models — pull one first'}
              />
            )}
          </div>
          <div className="flex items-center gap-0.5">
            <IconButton label="Conversation settings" icon={AdjustmentsHorizontalIcon} onClick={() => setSettingsOpen(true)} />
            <IconButton label="Regenerate last reply" icon={ArrowPathIcon} disabled={!messages.some((m) => m.role === 'user') || streaming} onClick={regenerate} />
            <IconButton label="Export as JSON" icon={ArrowDownTrayIcon} disabled={!messages.length} onClick={exportJson} />
          </div>
        </div>

        <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-5 sm:px-6" aria-live="polite">
          {loadingSession ? (
            <div className="flex h-full items-center justify-center"><Spinner className="size-6 text-slate-400" /></div>
          ) : !messages.length ? (
            <div className="flex h-full items-center justify-center">
              <EmptyState
                icon={CpuChipIcon}
                title="Start a conversation"
                description={modelOptions.length ? 'Ask your self-hosted model anything. Enter sends, Shift+Enter adds a line.' : 'No models are available yet. Pull or sync one on the Models page first.'}
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

        <div className="border-t border-slate-200 p-3 dark:border-white/10">
          <div className="mx-auto max-w-3xl">
            {images.length > 0 && (
              <div className="mb-2 flex flex-wrap gap-2">
                {images.map((src, j) => (
                  <div key={j} className="relative">
                    <img src={src} alt="Attachment" className="size-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10" />
                    <button type="button" aria-label="Remove image" onClick={() => setImages((p) => p.filter((_, k) => k !== j))} className="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-slate-700 text-white dark:bg-slate-600">
                      <XMarkIcon aria-hidden className="size-3" />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <div className="flex items-end gap-2">
              <input ref={fileRef} type="file" accept="image/*" multiple hidden onChange={(e) => attach(e.target.files)} />
              <IconButton
                label={isVision ? 'Attach images for the vision model' : 'Pick a vision model (e.g. llava, moondream) to attach images'}
                icon={isVision ? PhotoIcon : PaperClipIcon}
                disabled={!modelKey}
                className={cx('size-10 shrink-0 ring-1', isVision ? 'text-brand-600 ring-brand-200 dark:text-blue-400 dark:ring-blue-500/30' : 'ring-slate-200 dark:ring-white/10')}
                onClick={() => fileRef.current?.click()}
              />
              {(prompts.data?.length ?? 0) > 0 && (
                <IconButton label="Insert a saved prompt" icon={BookOpenIcon} className="hidden size-10 shrink-0 ring-1 ring-slate-200 sm:inline-flex dark:ring-white/10" onClick={() => setPromptsOpen(true)} />
              )}
              {can('chat.use') && (
                <VoiceInput
                  disabled={!modelKey || streaming}
                  onText={(t) => { setDraft((d) => (d && !/\s$/.test(d) ? `${d} ${t}` : `${d}${t}`)); inputRef.current?.focus(); }}
                />
              )}
              <textarea
                ref={inputRef}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={onKey}
                disabled={!modelKey}
                rows={Math.min(6, Math.max(1, draft.split('\n').length))}
                aria-label="Message"
                placeholder={isVision ? 'Ask about an attached image…' : 'Message…  (Enter to send, Shift+Enter for a new line)'}
                className="block min-h-10 w-full resize-none rounded-md bg-white px-3 py-2 text-base text-slate-900 outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:cursor-not-allowed disabled:bg-slate-50 sm:text-sm/6 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-slate-500 dark:focus:outline-blue-500 dark:disabled:bg-white/[0.02]"
              />
              {streaming ? (
                <Button icon={StopIcon} onClick={stop} className="h-10">Stop</Button>
              ) : (
                <Button variant="primary" icon={PaperAirplaneIcon} className="h-10" disabled={(!draft.trim() && !images.length) || !modelKey} onClick={() => void send()} aria-label="Send">
                  <span className="hidden sm:inline">Send</span>
                </Button>
              )}
            </div>
          </div>
        </div>
      </section>

      <Modal
        open={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        title="Conversation settings"
        description="Temperature, context length and keep-alive come from the model's own configuration on the Models page."
        footer={<Button variant="primary" onClick={() => setSettingsOpen(false)}>Done</Button>}
      >
        <div className="space-y-5">
          {activeAgents.length > 0 && (
            <Select
              label="Start from an agent"
              hint="Starts a new conversation with the agent's model and system prompt."
              value=""
              placeholder="Choose an agent…"
              options={activeAgents.map((a) => ({ value: a.id, label: `${a.avatar ?? ''} ${a.name}${a.model ? ` · ${a.model}` : ''}`.trim() }))}
              onChange={(e) => { const a = activeAgents.find((x) => x.id === Number(e.target.value)); if (a) { applyAgent(a); setSettingsOpen(false); } }}
            />
          )}
          <Textarea
            label="System prompt"
            rows={6}
            value={systemPrompt}
            disabled={locked}
            onChange={(e) => setSystemPrompt(e.target.value)}
            placeholder="You are a helpful assistant…"
            hint={locked ? 'Fixed for this conversation. Start a new chat to use a different system prompt.' : "Used for the new conversation. Leave empty for the model's default."}
          />
          {current && (
            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
              <Badge tone="blue">{current.model ?? model}</Badge>
              <span>{Number(current.message_count)} messages · updated {ago(current.updated_at)}</span>
            </div>
          )}
        </div>
      </Modal>

      <Modal open={promptsOpen} onClose={() => setPromptsOpen(false)} title="Insert a saved prompt" size="lg">
        <ul className="divide-y divide-slate-100 dark:divide-white/5">
          {(prompts.data ?? []).map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className="w-full rounded-md px-2 py-3 text-left hover:bg-slate-50 dark:hover:bg-white/5"
                onClick={() => { setDraft((d) => (d ? `${d}\n\n${p.content}` : p.content)); setPromptsOpen(false); inputRef.current?.focus(); }}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium text-slate-900 dark:text-white">{p.name}</span>
                  <Badge tone="blue">{p.category}</Badge>
                  {p.variables.length > 0 && <Badge tone="purple">{p.variables.length} variables</Badge>}
                </div>
                <p className="mt-1 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{p.content}</p>
              </button>
            </li>
          ))}
        </ul>
      </Modal>

      <FormModal
        open={!!renaming}
        onClose={() => setRenaming(null)}
        title="Rename conversation"
        submitLabel="Rename"
        submitting={rename.isPending}
        onSubmit={() => { if (renameTitle.trim()) rename.mutate(); }}
      >
        <Input
          label="Title"
          required
          autoFocus
          value={renameTitle}
          onChange={(e) => setRenameTitle(e.target.value)}
          error={rename.error instanceof ApiError ? rename.error.fields.title ?? rename.error.message : undefined}
        />
      </FormModal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        busy={remove.isPending}
        title="Delete conversation?"
        message={<>“{deleting?.title}” and all its messages will be removed. This cannot be undone.</>}
      />
    </div>
  );
}
