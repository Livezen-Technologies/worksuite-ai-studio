import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ConfirmDialog, FormModal, Input, useToast } from '../components/ui';
import { ApiError, del, get, put, streamNdjson } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ChatHeader } from './Chat/ChatHeader';
import { Composer } from './Chat/Composer';
import { ConversationList } from './Chat/ConversationList';
import { MessageList } from './Chat/MessageList';
import { PromptsModal } from './Chat/PromptsModal';
import { SettingsModal } from './Chat/SettingsModal';
import { VISION, isChatModel, keyOf, split, type Agent, type ModelOption, type ModelRow, type Msg, type Prompt, type Session } from './Chat/shared';

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

  const abortRef = useRef<AbortController | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
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
    // ?model=<name> (e.g. "Try in Chat" on a freshly built custom LLM) wins over the default model
    const wanted = new URLSearchParams(window.location.search).get('model');
    const pick = (wanted && usable.find((m) => m.name === wanted || m.name === `${wanted}:latest`))
      ?? usable.find((m) => Number(m.is_default) === 1) ?? usable[0];
    if (!modelKey && pick) setModelKey(keyOf(pick.server_id, pick.name));
  }, [models.data, modelKey]);

  // Arrive from Prompts ("Use in chat") or Agents ("Chat with agent"), also when already on
  // this page. applyAgent is read through a ref so the latest version runs.
  const applyAgentRef = useRef<(a: Agent) => void>(() => {});
  applyAgentRef.current = (a: Agent) => applyAgent(a);
  useEffect(() => {
    const st = location.state as { prompt?: string; agent?: Agent } | null;
    if (st?.prompt) setDraft(st.prompt);
    if (st?.agent) applyAgentRef.current(st.agent);
    if (st) window.history.replaceState({}, '');
  }, [location.key, location.state]);

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

  function exportJson() {
    const data = { session_id: sessionId, title: current?.title ?? null, model, system_prompt: systemPrompt || null, messages: messages.map(({ pending: _p, ...m }) => m) };
    const href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    Object.assign(document.createElement('a'), { href, download: `chat-${sessionId ?? 'new'}.json` }).click();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
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

  const modelOptions: ModelOption[] = useMemo(
    () => (models.data ?? []).filter(isChatModel)
      .sort((a, b) => Number(b.is_default ?? 0) - Number(a.is_default ?? 0) || a.name.localeCompare(b.name))
      .map((m) => ({ value: keyOf(m.server_id, m.name), label: `${m.name}${Number(m.is_default) === 1 ? ' (default)' : ''} · ${m.server_name}${m.server_status && m.server_status !== 'online' ? ` (${m.server_status})` : ''}` })),
    [models.data],
  );
  // A conversation's model may no longer be listed; keep it selectable.
  if (modelKey && !modelOptions.some((o) => o.value === modelKey)) modelOptions.unshift({ value: modelKey, label: split(modelKey).model });
  const activeAgents = (agents.data ?? []).filter((a) => a.status === 'active');

  const sidebar = (
    <ConversationList
      sessions={sessions}
      sessionId={sessionId}
      onNewChat={newChat}
      onClose={() => setSidebarOpen(false)}
      onOpen={(id) => void openSession(id)}
      onRename={(s) => { setRenaming(s); setRenameTitle(s.title); }}
      onDelete={(s) => setDeleting(s)}
    />
  );

  return (
    <div className="flex h-[calc(100dvh-9rem)] min-h-[28rem] gap-4">
      {/* Conversations: a column on desktop, a drawer on phones */}
      <aside className="hidden w-64 shrink-0 overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] md:block dark:border-white/10 dark:bg-slate-900 dark:shadow-none">{sidebar}</aside>
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-slate-900/50 dark:bg-slate-950/70" onClick={() => setSidebarOpen(false)} />
          <div className="absolute inset-y-0 left-0 w-72 max-w-[85vw] bg-white shadow-xl dark:bg-slate-900">{sidebar}</div>
        </div>
      )}

      <section className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-slate-900 dark:shadow-none">
        <ChatHeader
          title={current?.title ?? (sessionId ? 'Conversation' : 'New conversation')}
          systemPrompt={systemPrompt}
          modelsLoading={models.isLoading}
          modelKey={modelKey}
          onModelChange={setModelKey}
          modelOptions={modelOptions}
          canRegenerate={!(!messages.some((m) => m.role === 'user') || streaming)}
          canExport={!!messages.length}
          onOpenSidebar={() => setSidebarOpen(true)}
          onOpenSettings={() => setSettingsOpen(true)}
          onRegenerate={regenerate}
          onExport={exportJson}
        />

        <MessageList scrollRef={scrollRef} loading={loadingSession} messages={messages} hasModels={modelOptions.length > 0} />

        <Composer
          inputRef={inputRef}
          draft={draft}
          setDraft={setDraft}
          images={images}
          setImages={setImages}
          isVision={isVision}
          modelKey={modelKey}
          streaming={streaming}
          hasPrompts={(prompts.data?.length ?? 0) > 0}
          canVoice={can('chat.use')}
          onOpenPrompts={() => setPromptsOpen(true)}
          onSend={() => void send()}
          onStop={stop}
        />
      </section>

      <SettingsModal
        open={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        activeAgents={activeAgents}
        onPickAgent={(a) => { applyAgent(a); setSettingsOpen(false); }}
        systemPrompt={systemPrompt}
        setSystemPrompt={setSystemPrompt}
        locked={locked}
        current={current}
        model={model}
      />

      <PromptsModal
        open={promptsOpen}
        onClose={() => setPromptsOpen(false)}
        prompts={prompts.data ?? []}
        onPick={(p) => { setDraft((d) => (d ? `${d}\n\n${p.content}` : p.content)); setPromptsOpen(false); inputRef.current?.focus(); }}
      />

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
