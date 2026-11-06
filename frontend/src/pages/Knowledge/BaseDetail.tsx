import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowPathIcon, ArrowUpTrayIcon, DocumentTextIcon, MagnifyingGlassIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  Badge, Button, ConfirmDialog, DataTable, EmptyState, ErrorState, FormModal, Input, Modal, Segmented, Select,
  SkeletonRows, StatusBadge, Textarea, useToast, type Column,
} from '../../components/ui';
import { api, del, get, post } from '../../lib/api';
import { bytes, num } from '../../lib/format';
import { ACCEPT, type Base, type Doc, type SearchResult } from './shared';

export function BaseDetail({ id, canManage, onClose, onChanged, onDelete }: {
  id: number | null; canManage: boolean; onClose: () => void; onChanged: () => void; onDelete: (b: Base) => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [mode, setMode] = useState<'paste' | 'upload'>('paste');
  const [doc, setDoc] = useState({ title: '', content: '' });
  const [file, setFile] = useState<File | null>(null);
  const [query, setQuery] = useState('');
  const [k, setK] = useState('5');
  const [result, setResult] = useState<SearchResult | null>(null);
  const [removingDoc, setRemovingDoc] = useState<Doc | null>(null);

  const kb = useQuery({ queryKey: ['kb', id], queryFn: () => get<Base & { documents: Doc[] }>(`/knowledge/${id}`), enabled: !!id });
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['kb', id] }); onChanged(); };

  const add = useMutation({
    mutationFn: () => {
      if (mode === 'upload') {
        const fd = new FormData();
        fd.append('file', file!);
        if (doc.title.trim()) fd.append('title', doc.title.trim());
        return api<{ embedded?: boolean }>(`/knowledge/${id}/documents`, { method: 'POST', body: fd });
      }
      return post<{ embedded?: boolean }>(`/knowledge/${id}/documents`, { title: doc.title.trim() || 'Untitled', content: doc.content });
    },
    onSuccess: (r) => {
      if (r?.embedded) toast.success('Document added and embedded');
      else toast.info('Document added', 'Embeddings are pending — no online node. Use Re-embed later.');
      setAddOpen(false); setDoc({ title: '', content: '' }); setFile(null); refresh();
    },
    onError: (e) => toast.error(e),
  });
  const removeDoc = useMutation({
    mutationFn: (docId: number) => del(`/knowledge/documents/${docId}`),
    onSuccess: () => { toast.success('Document removed'); setRemovingDoc(null); refresh(); },
    onError: (e) => toast.error(e),
  });
  const reindex = useMutation({
    mutationFn: () => post<{ documents: number; embedded: number }>(`/knowledge/${id}/reindex`),
    onSuccess: (r) => { if (r.embedded) toast.success(`Re-embedded ${r.embedded} of ${r.documents} documents`); else toast.error('No online node available to embed.'); refresh(); },
    onError: (e) => toast.error(e),
  });
  const search = useMutation({
    mutationFn: () => post<SearchResult>(`/knowledge/${id}/search`, { query: query.trim(), k: Number(k) }),
    onSuccess: setResult,
    onError: (e) => toast.error(e),
  });

  const close = () => { setResult(null); setQuery(''); onClose(); };
  const data = kb.data;
  const docTitle = (docId: number) => data?.documents.find((d) => d.id === docId)?.title;

  const docColumns: Column<Doc>[] = [
    {
      key: 'title', header: 'Document', cell: (d) => (
        <div className="flex min-w-0 items-center gap-2">
          <DocumentTextIcon aria-hidden className="size-4 shrink-0 text-slate-400" />
          <div className="min-w-0">
            <div className="truncate">{d.title}</div>
            <div className="truncate text-xs font-normal text-slate-500 dark:text-slate-400">{d.filename ?? (d.source_type === 'text' ? 'Pasted text' : d.source_type)}</div>
          </div>
        </div>
      ),
    },
    { key: 'chunks', header: 'Chunks', align: 'right', hideBelow: 'sm', cell: (d) => <span className="tabular-nums">{num(d.chunk_count)}</span> },
    { key: 'size', header: 'Size', align: 'right', hideBelow: 'md', cell: (d) => bytes(d.size_bytes) },
    {
      key: 'status', header: 'Status', cell: (d) => (
        <div className="flex flex-wrap gap-1">
          <StatusBadge status={d.status} />
          {Number(d.embedded) === 1 ? <Badge tone="green">embedded</Badge> : <Badge tone="amber">not embedded</Badge>}
        </div>
      ),
    },
    ...(canManage ? [{
      key: 'x', header: <span className="sr-only">Actions</span>, align: 'right' as const, cell: (d: Doc) => (
        <Button size="sm" variant="ghost" icon={TrashIcon} aria-label={`Delete ${d.title}`} onClick={() => setRemovingDoc(d)} />
      ),
    }] : []),
  ];

  return (
    <>
      <Modal
        open={!!id}
        onClose={close}
        size="xl"
        title={data?.name ?? 'Knowledge base'}
        description={data ? `${data.description ? `${data.description} · ` : ''}${data.embed_model} · ${num(data.chunk_size)}-character chunks` : undefined}
      >
        {kb.error ? (
          <ErrorState error={kb.error} onRetry={() => void kb.refetch()} />
        ) : !data ? (
          <SkeletonRows rows={4} cols={3} />
        ) : (
          <div className="space-y-6">
            {canManage && (
              <div className="flex flex-wrap items-center gap-2">
                <Button variant="primary" size="sm" icon={PlusIcon} onClick={() => { add.reset(); setAddOpen(true); }}>Add document</Button>
                <Button size="sm" icon={ArrowPathIcon} loading={reindex.isPending} disabled={!data.documents.length} onClick={() => reindex.mutate()}>Re-embed all</Button>
                <Button size="sm" variant="ghost" icon={TrashIcon} className="ml-auto text-red-600 dark:text-red-400" onClick={() => onDelete(data)}>Delete base</Button>
              </div>
            )}

            <section>
              <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">Documents ({data.documents.length})</h3>
              <div className="overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-white/10">
                <DataTable
                  dense
                  columns={docColumns}
                  rows={data.documents}
                  rowKey={(d) => d.id}
                  empty={<EmptyState icon={DocumentTextIcon} title="No documents" description="Paste text or upload a file to start." action={canManage ? <Button size="sm" variant="primary" icon={PlusIcon} onClick={() => setAddOpen(true)}>Add document</Button> : undefined} />}
                />
              </div>
            </section>

            <section>
              <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">Test retrieval</h3>
              <form className="flex flex-col gap-2 sm:flex-row" onSubmit={(e) => { e.preventDefault(); if (query.trim()) search.mutate(); }}>
                <Input aria-label="Question" wrapperClassName="flex-1" placeholder="Ask a question to see which chunks come back…" value={query} onChange={(e) => setQuery(e.target.value)} />
                <div className="w-full sm:w-28">
                  <Select aria-label="Results" value={k} onChange={(e) => setK(e.target.value)} options={[3, 5, 10, 20].map((n) => ({ value: n, label: `Top ${n}` }))} />
                </div>
                <Button type="submit" icon={MagnifyingGlassIcon} loading={search.isPending} disabled={!query.trim()}>Search</Button>
              </form>
              {result && (
                <div className="mt-3 space-y-2">
                  <p className="text-xs text-slate-500 dark:text-slate-400">{result.results.length} results · {result.mode} search</p>
                  {result.results.length === 0 && <p className="text-sm text-slate-500 dark:text-slate-400">Nothing relevant found.</p>}
                  {result.results.map((r, i) => (
                    <div key={i} className="rounded-md bg-slate-50 p-3 text-xs text-slate-700 dark:bg-white/5 dark:text-slate-300">
                      <div className="mb-1 flex flex-wrap items-center gap-2">
                        {r.score != null && <Badge tone="green">score {r.score}</Badge>}
                        {docTitle(r.document_id) && <span className="text-slate-500 dark:text-slate-400">{docTitle(r.document_id)}</span>}
                      </div>
                      <p className="line-clamp-4 whitespace-pre-wrap">{r.content}</p>
                    </div>
                  ))}
                </div>
              )}
            </section>
          </div>
        )}
      </Modal>

      <FormModal
        open={addOpen}
        onClose={() => setAddOpen(false)}
        title="Add document"
        description="Text is split into chunks and embedded for retrieval."
        submitLabel="Add"
        size="lg"
        submitting={add.isPending}
        onSubmit={() => {
          if (mode === 'upload' && !file) { toast.error('Choose a file to upload.'); return; }
          if (mode === 'paste' && !doc.content.trim()) { toast.error('Paste some text first.'); return; }
          add.mutate();
        }}
      >
        <Segmented label="Source" value={mode} onChange={setMode} options={[{ value: 'paste', label: 'Paste text' }, { value: 'upload', label: 'Upload file' }]} />
        <Input label="Title" maxLength={191} value={doc.title} onChange={(e) => setDoc({ ...doc, title: e.target.value })} placeholder={mode === 'upload' ? 'Defaults to the file name' : 'Untitled'} />
        {mode === 'paste' ? (
          <Textarea label="Content" required rows={10} value={doc.content} onChange={(e) => setDoc({ ...doc, content: e.target.value })} placeholder="Paste document text…" />
        ) : (
          <div>
            <input ref={fileRef} type="file" accept={ACCEPT} hidden onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <button
              type="button"
              onClick={() => fileRef.current?.click()}
              className="flex w-full flex-col items-center gap-2 rounded-lg border-2 border-dashed border-slate-300 px-6 py-8 text-center hover:border-brand-500 dark:border-white/15 dark:hover:border-blue-400"
            >
              <ArrowUpTrayIcon aria-hidden className="size-8 text-slate-400" />
              <span className="text-sm font-medium text-slate-900 dark:text-white">{file ? file.name : 'Choose a file'}</span>
              <span className="text-xs text-slate-500 dark:text-slate-400">{file ? bytes(file.size) : 'txt, md, csv, tsv, json, log, html, xml, yaml'}</span>
            </button>
            <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">PDF and Word files are not supported yet — paste their text instead.</p>
          </div>
        )}
      </FormModal>

      <ConfirmDialog
        open={!!removingDoc}
        onClose={() => setRemovingDoc(null)}
        onConfirm={() => removingDoc && removeDoc.mutate(removingDoc.id)}
        busy={removeDoc.isPending}
        title="Delete document?"
        message={<>“{removingDoc?.title}” and its chunks will be removed from this knowledge base.</>}
      />
    </>
  );
}
