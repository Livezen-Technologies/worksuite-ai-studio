import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyIcon, LockClosedIcon, PencilSquareIcon, PlusIcon, ShieldCheckIcon, TrashIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Alert, Badge, Button, Card, Checkbox, ConfirmDialog, EmptyState, ErrorState, FormModal, Input, PageHeader,
  SearchInput, Skeleton, useToast,
} from '../components/ui';
import { ApiError, del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';

interface Role {
  id: number;
  name: string;
  label: string;
  description?: string | null;
  is_system: number | string | boolean;
  user_count: number;
  permissions: string[];
}
interface Permission { id: number; name: string; label: string; module: string }
type Catalog = Record<string, Permission[]>;

const isSystem = (r: Role) => Number(r.is_system) === 1 || r.is_system === true;
const isSuper = (r: Role | null) => r?.name === 'super_admin';
const titleCase = (s: string) => s.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function Roles() {
  const qc = useQueryClient();
  const toast = useToast();
  const { can } = useAuth();
  const canManage = can('roles.manage');

  const roles = useQuery({ queryKey: ['roles'], queryFn: () => get<Role[]>('/roles') });
  const catalog = useQuery({ queryKey: ['permissions'], queryFn: () => get<Catalog>('/roles/permissions') });

  const [editor, setEditor] = useState<{ role: Role | null } | null>(null);
  const [deleting, setDeleting] = useState<Role | null>(null);

  const remove = useMutation({
    mutationFn: (r: Role) => del(`/roles/${r.id}`),
    onSuccess: () => { toast.success('Role deleted'); setDeleting(null); qc.invalidateQueries({ queryKey: ['roles'] }); },
    onError: (e) => toast.error(e),
  });

  const total = useMemo(() => Object.values(catalog.data ?? {}).reduce((n, g) => n + g.length, 0), [catalog.data]);

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        description="What each role can see and do. System roles are built in and cannot be deleted."
        actions={canManage && (
          <Button variant="primary" icon={PlusIcon} onClick={() => setEditor({ role: null })} disabled={!catalog.data}>New role</Button>
        )}
      />

      {roles.error ? (
        <Card><ErrorState error={roles.error} onRetry={() => roles.refetch()} /></Card>
      ) : roles.isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((i) => <Card key={i} padded><Skeleton className="h-24" /></Card>)}
        </div>
      ) : !roles.data?.length ? (
        <Card>
          <EmptyState
            icon={ShieldCheckIcon}
            title="No roles yet"
            description="Create a role and choose what it may do."
            action={canManage && <Button variant="primary" icon={PlusIcon} onClick={() => setEditor({ role: null })}>New role</Button>}
          />
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {roles.data.map((r) => (
            <Card key={r.id} padded className="flex flex-col overflow-visible!">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5 font-semibold text-slate-900 dark:text-white">
                    <span className="truncate">{r.label}</span>
                    {isSystem(r) && <LockClosedIcon className="size-4 shrink-0 text-slate-400 dark:text-slate-500" aria-label="System role" />}
                  </div>
                  <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{r.description || <code className="text-xs">{r.name}</code>}</p>
                </div>
                {canManage ? (
                  <ActionMenu
                    actions={[
                      { label: isSuper(r) ? 'Edit details' : 'Edit role & permissions', icon: PencilSquareIcon, onClick: () => setEditor({ role: r }), disabled: !catalog.data },
                      { label: 'Delete', icon: TrashIcon, danger: true, onClick: () => setDeleting(r), hidden: isSystem(r), disabled: r.user_count > 0 },
                    ]}
                  />
                ) : (
                  <ShieldCheckIcon className="size-5 shrink-0 text-brand-500 dark:text-blue-400" aria-hidden />
                )}
              </div>
              <div className="mt-4 flex flex-wrap items-center gap-2">
                <Badge>{r.user_count} {r.user_count === 1 ? 'user' : 'users'}</Badge>
                <Badge tone="blue">
                  {isSuper(r) ? 'All permissions' : `${r.permissions?.length ?? 0}${total ? ` of ${total}` : ''} permissions`}
                </Badge>
                {isSystem(r) && <Badge tone="gray">System</Badge>}
              </div>
              {canManage && !isSystem(r) && r.user_count > 0 && (
                <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Reassign its users before deleting this role.</p>
              )}
              {canManage && (
                <div className="mt-auto pt-4">
                  <Button size="sm" variant="soft" icon={KeyIcon} onClick={() => setEditor({ role: r })} disabled={!catalog.data}>
                    {isSuper(r) ? 'View permissions' : 'Edit permissions'}
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      {catalog.error && !roles.error && (
        <div className="mt-4"><Alert tone="warning" title="The permission catalogue could not be loaded">Roles can be listed, but not edited until it loads.</Alert></div>
      )}

      {editor && catalog.data && (
        <RoleEditor role={editor.role} catalog={catalog.data} onClose={() => setEditor(null)} />
      )}

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting)}
        busy={remove.isPending}
        title="Delete this role?"
        message={<>The role “{deleting?.label}” and its permission set will be removed.</>}
        confirmLabel="Delete role"
      />
    </>
  );
}

function RoleEditor({ role, catalog, onClose }: { role: Role | null; catalog: Catalog; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const locked = isSuper(role);
  const [label, setLabel] = useState(role?.label ?? '');
  const [description, setDescription] = useState(role?.description ?? '');
  const [selected, setSelected] = useState<number[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    const byName = new Map<string, number>();
    Object.values(catalog).flat().forEach((p) => byName.set(p.name, p.id));
    const all = Object.values(catalog).flat().map((p) => p.id);
    setSelected(locked ? all : (role?.permissions ?? []).map((n) => byName.get(n)).filter((v): v is number => !!v));
  }, [role, catalog, locked]);

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { label: label.trim(), description: description.trim() };
      if (!locked) body.permission_ids = selected;
      return role ? put(`/roles/${role.id}`, body) : post('/roles', body);
    },
    onSuccess: () => {
      toast.success(role ? 'Role updated' : 'Role created');
      qc.invalidateQueries({ queryKey: ['roles'] });
      onClose();
    },
    onError: (e) => {
      if (e instanceof ApiError) setErrors(e.fields);
      toast.error(e);
    },
  });

  const submit = () => {
    if (label.trim().length < 2) { setErrors({ label: 'A role name of at least 2 characters is required.' }); return; }
    setErrors({});
    save.mutate();
  };

  return (
    <FormModal
      open
      onClose={onClose}
      size="xl"
      title={role ? `Edit ${role.label}` : 'New role'}
      description={role ? undefined : 'Give the role a name, then choose what it may do.'}
      onSubmit={submit}
      submitLabel={role ? 'Save changes' : 'Create role'}
      submitting={save.isPending}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Input label="Name" required value={label} onChange={(e) => setLabel(e.target.value)} error={errors.label} placeholder="e.g. Analyst" autoFocus />
        <Input label="Description" value={description} onChange={(e) => setDescription(e.target.value)} error={errors.description} placeholder="What this role is for" />
      </div>
      {locked && <Alert tone="warning">Super Admin always has every permission; its permission set cannot be changed.</Alert>}
      <PermissionMatrix catalog={catalog} value={selected} onChange={setSelected} disabled={locked} />
    </FormModal>
  );
}

function PermissionMatrix({ catalog, value, onChange, disabled }: { catalog: Catalog; value: number[]; onChange: (v: number[]) => void; disabled?: boolean }) {
  const [q, setQ] = useState('');
  const set = useMemo(() => new Set(value), [value]);
  const term = q.trim().toLowerCase();
  const groups = Object.entries(catalog)
    .map(([module, perms]) => [module, perms.filter((p) => !term || `${module} ${p.label} ${p.name}`.toLowerCase().includes(term))] as const)
    .filter(([, perms]) => perms.length);
  const all = Object.values(catalog).flat().map((p) => p.id);

  const toggle = (id: number) => onChange(set.has(id) ? value.filter((v) => v !== id) : [...value, id]);
  const toggleGroup = (perms: readonly Permission[], on: boolean) => {
    const ids = perms.map((p) => p.id);
    onChange(on ? [...new Set([...value, ...ids])] : value.filter((v) => !ids.includes(v)));
  };

  return (
    <div>
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="text-sm font-medium text-slate-900 dark:text-white">
          Permissions <span className="font-normal text-slate-500 dark:text-slate-400">· {value.length} of {all.length} selected</span>
        </div>
        <div className="flex items-center gap-2">
          <SearchInput value={q} onChange={setQ} placeholder="Filter permissions…" className="w-full sm:w-56" />
          {!disabled && (
            <>
              <Button size="sm" variant="ghost" onClick={() => onChange(all)}>All</Button>
              <Button size="sm" variant="ghost" onClick={() => onChange([])}>None</Button>
            </>
          )}
        </div>
      </div>
      <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
        {groups.map(([module, perms]) => {
          const on = perms.filter((p) => set.has(p.id)).length;
          const allOn = on === perms.length;
          return (
            <fieldset key={module} className="rounded-lg border border-slate-200 p-3 dark:border-white/10">
              <legend className="sr-only">{titleCase(module)}</legend>
              <div className="flex items-center justify-between gap-2 border-b border-slate-100 pb-2 dark:border-white/5">
                <Checkbox
                  checked={allOn}
                  disabled={disabled}
                  onChange={(v) => toggleGroup(perms, v)}
                  label={<span className="font-semibold text-slate-900 dark:text-white">{titleCase(module)}</span>}
                />
                <span className="text-xs text-slate-500 dark:text-slate-400">{on}/{perms.length}</span>
              </div>
              <div className="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                {perms.map((p) => (
                  <Checkbox
                    key={p.id}
                    checked={set.has(p.id)}
                    disabled={disabled}
                    onChange={() => toggle(p.id)}
                    label={<span title={p.name}>{p.label}</span>}
                  />
                ))}
              </div>
            </fieldset>
          );
        })}
        {!groups.length && <p className="text-sm text-slate-500 dark:text-slate-400">No permissions match “{q}”.</p>}
      </div>
    </div>
  );
}
