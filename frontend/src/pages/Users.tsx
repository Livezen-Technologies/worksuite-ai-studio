import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, NoSymbolIcon, PencilSquareIcon, PlusIcon, TrashIcon, UsersIcon } from '@heroicons/react/24/outline';
import {
  ActionMenu, Badge, Button, Card, ConfirmDialog, DataTable, EmptyState, FormModal, Input, PageHeader, SearchInput, Select,
  Toolbar, useToast, type Column,
} from '../components/ui';
import { ApiError, del, get, post, put } from '../lib/api';
import { ago, dateTime } from '../lib/format';
import { useAuth } from '../lib/auth';

interface UserRow {
  id: number;
  name: string;
  email: string;
  status: string;
  role_id: number | null;
  role?: string | null;
  role_label?: string | null;
  last_login_at?: string | null;
  created_at?: string | null;
}
interface Role { id: number; name: string; label: string }

interface FormState { name: string; email: string; password: string; role_id: string; status: string }
const blank: FormState = { name: '', email: '', password: '', role_id: '', status: 'active' };
const STATUS_OPTIONS = [{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }];

export default function Users() {
  const qc = useQueryClient();
  const toast = useToast();
  const { can, user: me } = useAuth();
  const canManage = can('users.manage');

  const users = useQuery({ queryKey: ['users'], queryFn: () => get<UserRow[]>('/users') });
  const roles = useQuery({ queryKey: ['roles'], queryFn: () => get<Role[]>('/roles'), enabled: can('roles.view') });

  const [q, setQ] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const [editing, setEditing] = useState<UserRow | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<FormState>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [deleting, setDeleting] = useState<UserRow | null>(null);

  // Role options come from the roles endpoint; without roles.view, fall back to the roles already on users.
  const roleOptions = useMemo(() => {
    if (roles.data) return roles.data.map((r) => ({ value: String(r.id), label: r.label }));
    const seen = new Map<string, string>();
    users.data?.forEach((u) => { if (u.role_id) seen.set(String(u.role_id), u.role_label ?? u.role ?? `Role ${u.role_id}`); });
    return [...seen].map(([value, label]) => ({ value, label }));
  }, [roles.data, users.data]);

  const rows = useMemo(() => {
    const term = q.trim().toLowerCase();
    return (users.data ?? []).filter((u) =>
      (!term || `${u.name} ${u.email}`.toLowerCase().includes(term))
      && (!roleFilter || String(u.role_id) === roleFilter)
      && (!statusFilter || u.status === statusFilter));
  }, [users.data, q, roleFilter, statusFilter]);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['users'] });
    qc.invalidateQueries({ queryKey: ['roles'] });
  };

  const save = useMutation({
    mutationFn: (f: FormState) => {
      const body: Record<string, unknown> = { name: f.name.trim(), email: f.email.trim(), role_id: Number(f.role_id), status: f.status };
      if (f.password) body.password = f.password;
      return editing ? put<UserRow>(`/users/${editing.id}`, body) : post<UserRow>('/users', body);
    },
    onSuccess: () => {
      toast.success(editing ? 'User updated' : 'User created');
      setFormOpen(false);
      invalidate();
    },
    onError: (e) => {
      if (e instanceof ApiError) setErrors(e.fields);
      toast.error(e);
    },
  });

  const setStatus = useMutation({
    mutationFn: ({ u, status }: { u: UserRow; status: string }) => put(`/users/${u.id}`, { status }),
    onSuccess: (_d, v) => { toast.success(v.status === 'active' ? 'User reactivated' : 'User suspended'); invalidate(); },
    onError: (e) => toast.error(e),
  });

  const remove = useMutation({
    mutationFn: (u: UserRow) => del(`/users/${u.id}`),
    onSuccess: () => { toast.success('User deleted'); setDeleting(null); invalidate(); },
    onError: (e) => toast.error(e),
  });

  const openCreate = () => {
    setEditing(null);
    setForm({ ...blank, role_id: roleOptions[0]?.value ?? '' });
    setErrors({});
    setFormOpen(true);
  };
  const openEdit = (u: UserRow) => {
    setEditing(u);
    setForm({ name: u.name, email: u.email, password: '', role_id: u.role_id ? String(u.role_id) : '', status: u.status || 'active' });
    setErrors({});
    setFormOpen(true);
  };

  const submit = () => {
    const errs: Record<string, string> = {};
    if (form.name.trim().length < 2) errs.name = 'Enter a name of at least 2 characters.';
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) errs.email = 'Enter a valid email address.';
    if ((!editing || form.password) && form.password.length < 8) errs.password = 'The password must be at least 8 characters.';
    if (!form.role_id) errs.role_id = 'Choose a role.';
    setErrors(errs);
    if (!Object.keys(errs).length) save.mutate(form);
  };

  const set = (k: keyof FormState) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
  const isMe = (u: UserRow | null) => !!u && me?.id === u.id;

  const columns: Column<UserRow>[] = [
    {
      key: 'user',
      header: 'User',
      cell: (u) => (
        <div className="flex items-center gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-blue-300">
            {u.name.slice(0, 1).toUpperCase()}
          </span>
          <div className="min-w-0">
            <div className="truncate font-medium text-slate-900 dark:text-white">
              {u.name}
              {isMe(u) && <span className="ml-2 text-xs font-normal text-slate-500 dark:text-slate-400">(you)</span>}
            </div>
            <div className="truncate text-xs text-slate-500 dark:text-slate-400">{u.email}</div>
          </div>
        </div>
      ),
    },
    { key: 'role', header: 'Role', hideBelow: 'sm', cell: (u) => <Badge tone="blue">{u.role_label ?? u.role ?? '—'}</Badge> },
    {
      key: 'status',
      header: 'Status',
      cell: (u) => <Badge tone={u.status === 'active' ? 'green' : 'red'} dot>{u.status === 'active' ? 'Active' : 'Suspended'}</Badge>,
    },
    {
      key: 'login',
      header: 'Last sign-in',
      hideBelow: 'md',
      cell: (u) => (
        <span className="text-sm text-slate-500 dark:text-slate-400" title={u.last_login_at ? dateTime(u.last_login_at) : undefined}>
          {u.last_login_at ? ago(u.last_login_at) : 'Never'}
        </span>
      ),
    },
    {
      key: 'created',
      header: 'Added',
      hideBelow: 'lg',
      cell: (u) => <span className="text-sm text-slate-500 dark:text-slate-400">{u.created_at ? dateTime(u.created_at) : '—'}</span>,
    },
  ];
  if (canManage) {
    columns.push({
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      cell: (u) => (
        <ActionMenu
          actions={[
            { label: 'Edit', icon: PencilSquareIcon, onClick: () => openEdit(u) },
            u.status === 'active'
              ? { label: 'Suspend', icon: NoSymbolIcon, onClick: () => setStatus.mutate({ u, status: 'suspended' }), disabled: isMe(u) || setStatus.isPending }
              : { label: 'Reactivate', icon: CheckCircleIcon, onClick: () => setStatus.mutate({ u, status: 'active' }), disabled: setStatus.isPending },
            { label: 'Delete', icon: TrashIcon, danger: true, onClick: () => setDeleting(u), disabled: isMe(u) },
          ]}
        />
      ),
    });
  }

  const filtered = Boolean(q || roleFilter || statusFilter);

  return (
    <>
      <PageHeader
        title="Users"
        description="People who can sign in to AI Studio, their role, and whether they may sign in."
        actions={canManage && <Button variant="primary" icon={PlusIcon} onClick={openCreate}>Add user</Button>}
      />

      <Card>
        <Toolbar
          right={
            <div className="flex gap-2">
              <Select aria-label="Filter by role" value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)} options={[{ value: '', label: 'All roles' }, ...roleOptions]} />
              <Select aria-label="Filter by status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} options={[{ value: '', label: 'Any status' }, ...STATUS_OPTIONS]} />
            </div>
          }
        >
          <SearchInput value={q} onChange={setQ} placeholder="Search name or email…" className="w-full sm:max-w-xs" />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={users.data ? rows : undefined}
          rowKey={(u) => u.id}
          loading={users.isLoading}
          error={users.error}
          onRetry={() => users.refetch()}
          empty={
            filtered ? (
              <EmptyState
                icon={UsersIcon}
                title="No users match these filters"
                action={<Button onClick={() => { setQ(''); setRoleFilter(''); setStatusFilter(''); }}>Clear filters</Button>}
              />
            ) : (
              <EmptyState
                icon={UsersIcon}
                title="No users yet"
                description="Add the people who should be able to sign in."
                action={canManage && <Button variant="primary" icon={PlusIcon} onClick={openCreate}>Add user</Button>}
              />
            )
          }
        />
      </Card>

      <FormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editing ? `Edit ${editing.name}` : 'Add user'}
        description={editing ? 'Change their details, role or status.' : 'They sign in with this email and password.'}
        onSubmit={submit}
        submitLabel={editing ? 'Save changes' : 'Create user'}
        submitting={save.isPending}
      >
        <Input label="Name" required value={form.name} onChange={(e) => set('name')(e.target.value)} error={errors.name} autoFocus />
        <Input label="Email" type="email" required value={form.email} onChange={(e) => set('email')(e.target.value)} error={errors.email} autoComplete="off" />
        <Input
          label={editing ? 'New password' : 'Password'}
          type="password"
          required={!editing}
          value={form.password}
          onChange={(e) => set('password')(e.target.value)}
          error={errors.password}
          hint={editing ? 'Leave blank to keep the current password. At least 8 characters.' : 'At least 8 characters.'}
          autoComplete="new-password"
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select label="Role" required value={form.role_id} onChange={(e) => set('role_id')(e.target.value)} options={roleOptions} placeholder="Choose a role" error={errors.role_id} />
          <Select
            label="Status"
            value={form.status}
            onChange={(e) => set('status')(e.target.value)}
            options={STATUS_OPTIONS}
            disabled={isMe(editing)}
            hint={isMe(editing) ? 'You cannot suspend yourself.' : form.status === 'suspended' ? 'Suspended users cannot sign in.' : undefined}
            error={errors.status}
          />
        </div>
      </FormModal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting)}
        busy={remove.isPending}
        title="Delete this user?"
        message={<>{deleting?.name} ({deleting?.email}) will no longer be able to sign in. The last super admin cannot be deleted.</>}
        confirmLabel="Delete user"
      />
    </>
  );
}
