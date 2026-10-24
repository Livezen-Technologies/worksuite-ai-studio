import { useEffect, useMemo, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  Combobox, ComboboxInput, ComboboxOption, ComboboxOptions, Dialog, DialogBackdrop, DialogPanel,
  Menu, MenuButton, MenuItem, MenuItems, Popover, PopoverButton, PopoverPanel, TransitionChild,
} from '@headlessui/react';
import {
  ArrowRightStartOnRectangleIcon, Bars3Icon, BellIcon, ComputerDesktopIcon, MagnifyingGlassIcon, MoonIcon, SunIcon, UserCircleIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import { ChevronDownIcon, ChevronRightIcon, HomeIcon } from '@heroicons/react/20/solid';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useAuth } from '../lib/auth';
import { useTheme, type Theme } from '../lib/theme';
import { get, post } from '../lib/api';
import { ago } from '../lib/format';
import { cx } from '../components/ui';
import { NAV, ALL_ITEMS, findItem } from './nav';
import { Logo } from './Logo';

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can } = useAuth();
  return (
    <div className="flex grow flex-col gap-y-5 overflow-y-auto border-r border-slate-200 bg-white px-4 pb-4 dark:border-white/10 dark:bg-slate-900">
      <div className="flex h-16 shrink-0 items-center px-2">
        <Link to="/" onClick={onNavigate} aria-label="WorkSuite AI Studio home"><Logo /></Link>
      </div>
      <nav className="flex flex-1 flex-col" aria-label="Main">
        <ul role="list" className="flex flex-1 flex-col gap-y-6">
          {NAV.map((section) => {
            const items = section.items.filter((i) => can(i.perm));
            if (!items.length) return null;
            return (
              <li key={section.heading}>
                <div className="px-2 text-xs/6 font-semibold tracking-wide text-slate-400 uppercase dark:text-slate-500">{section.heading}</div>
                <ul role="list" className="mt-1 space-y-0.5">
                  {items.map((item) => (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        end={item.to === '/'}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                          cx(
                            'group flex items-center gap-x-3 rounded-md px-2 py-1.5 text-sm/6 font-medium transition-colors',
                            isActive
                              ? 'bg-brand-50 text-brand-700 dark:bg-white/5 dark:text-white'
                              : 'text-slate-700 hover:bg-slate-50 hover:text-brand-700 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white',
                          )
                        }
                      >
                        {({ isActive }) => (
                          <>
                            <item.icon aria-hidden className={cx('size-5 shrink-0', isActive ? 'text-brand-600 dark:text-white' : 'text-slate-400 group-hover:text-brand-600 dark:group-hover:text-white')} />
                            {item.label}
                          </>
                        )}
                      </NavLink>
                    </li>
                  ))}
                </ul>
              </li>
            );
          })}
        </ul>
      </nav>
    </div>
  );
}

/** ⌘K / Ctrl+K: jump to any page you may open. */
function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const items = useMemo(() => {
    const q = query.trim().toLowerCase();
    return ALL_ITEMS.filter((i) => can(i.perm)).filter((i) => !q || `${i.label} ${i.section} ${i.description}`.toLowerCase().includes(q));
  }, [query, can]);
  return (
    <Dialog open={open} onClose={() => { onClose(); setQuery(''); }} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-900/40 transition-opacity data-closed:opacity-0 data-enter:duration-200 data-leave:duration-150 dark:bg-slate-950/70" />
      <div className="fixed inset-0 z-10 w-screen overflow-y-auto p-4 sm:p-6 md:p-20">
        <DialogPanel transition className="mx-auto max-w-xl transform overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/5 transition-all data-closed:scale-95 data-closed:opacity-0 data-enter:duration-200 data-leave:duration-150 dark:bg-slate-900 dark:ring-white/10">
          <Combobox
            onChange={(item: (typeof ALL_ITEMS)[number] | null) => {
              if (item) { navigate(item.to); onClose(); setQuery(''); }
            }}
          >
            <div className="grid grid-cols-1">
              <ComboboxInput
                autoFocus
                className="col-start-1 row-start-1 h-12 w-full bg-transparent pr-4 pl-11 text-base text-slate-900 outline-hidden placeholder:text-slate-400 sm:text-sm dark:text-white"
                placeholder="Go to a page…"
                onChange={(e) => setQuery(e.target.value)}
                onBlur={() => setQuery('')}
              />
              <MagnifyingGlassIcon aria-hidden className="pointer-events-none col-start-1 row-start-1 ml-4 size-5 self-center text-slate-400" />
            </div>
            {items.length > 0 ? (
              <ComboboxOptions static className="max-h-80 scroll-py-2 overflow-y-auto border-t border-slate-100 py-2 dark:border-white/10">
                {items.map((item) => (
                  <ComboboxOption key={item.to} value={item} className="group flex cursor-default items-center gap-3 px-4 py-2 select-none data-focus:bg-brand-600 data-focus:outline-hidden">
                    <item.icon aria-hidden className="size-5 flex-none text-slate-400 group-data-focus:text-white" />
                    <span className="flex-auto">
                      <span className="block text-sm font-medium text-slate-900 group-data-focus:text-white dark:text-white">{item.label}</span>
                      <span className="block text-xs text-slate-500 group-data-focus:text-blue-100 dark:text-slate-400">{item.description}</span>
                    </span>
                    <span className="text-xs text-slate-400 group-data-focus:text-blue-100">{item.section}</span>
                  </ComboboxOption>
                ))}
              </ComboboxOptions>
            ) : (
              <p className="border-t border-slate-100 p-4 text-sm text-slate-500 dark:border-white/10">No page matches “{query}”.</p>
            )}
          </Combobox>
        </DialogPanel>
      </div>
    </Dialog>
  );
}

interface Note { id: number; title?: string; message?: string; body?: string; type?: string; level?: string; read_at?: string | null; created_at?: string }

function NotificationsBell() {
  const qc = useQueryClient();
  const { data } = useQuery({ queryKey: ['notifications', 'mine'], queryFn: () => get<{ rows: Note[]; unread: number }>('/notifications'), refetchInterval: 60_000 });
  const markAll = useMutation({ mutationFn: () => post('/notifications/read', {}), onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }) });
  const unread = data?.unread ?? 0;
  return (
    <Popover className="relative">
      <PopoverButton className="relative -m-2.5 rounded-md p-2.5 text-slate-400 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:text-white">
        <span className="sr-only">Notifications{unread ? `, ${unread} unread` : ''}</span>
        <BellIcon aria-hidden className="size-6" />
        {unread > 0 && (
          <span className="absolute top-1.5 right-1.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white tabular-nums ring-2 ring-white dark:ring-slate-900">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </PopoverButton>
      <PopoverPanel transition anchor="bottom end" className="z-50 mt-2 w-80 rounded-lg bg-white shadow-lg ring-1 ring-slate-900/5 transition data-closed:translate-y-1 data-closed:opacity-0 data-enter:duration-150 data-leave:duration-100 dark:bg-slate-800 dark:ring-white/10">
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-white/10">
          <p className="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
          {unread > 0 && (
            <button type="button" onClick={() => markAll.mutate()} className="text-xs font-semibold text-brand-600 hover:text-brand-500 dark:text-blue-400">Mark all read</button>
          )}
        </div>
        <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-white/5">
          {(data?.rows ?? []).slice(0, 12).map((n) => (
            <li key={n.id} className={cx('px-4 py-3', !n.read_at && 'bg-brand-50/50 dark:bg-blue-500/5')}>
              <p className="text-sm font-medium text-slate-900 dark:text-white">{n.title ?? n.type ?? 'Notification'}</p>
              {(n.message ?? n.body) && <p className="mt-0.5 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{n.message ?? n.body}</p>}
              <p className="mt-1 text-xs text-slate-400">{ago(n.created_at)}</p>
            </li>
          ))}
          {!data?.rows?.length && <li className="px-4 py-8 text-center text-sm text-slate-500">You're all caught up.</li>}
        </ul>
        <Link to="/notifications" className="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-semibold text-brand-600 hover:bg-slate-50 dark:border-white/10 dark:text-blue-400 dark:hover:bg-white/5">
          View all
        </Link>
      </PopoverPanel>
    </Popover>
  );
}

const themes: { value: Theme; label: string; icon: typeof SunIcon }[] = [
  { value: 'light', label: 'Light', icon: SunIcon },
  { value: 'dark', label: 'Dark', icon: MoonIcon },
  { value: 'system', label: 'System', icon: ComputerDesktopIcon },
];

function ThemeMenu() {
  const { theme, setTheme } = useTheme();
  const Current = themes.find((t) => t.value === theme)?.icon ?? ComputerDesktopIcon;
  return (
    <Menu as="div" className="relative">
      <MenuButton className="-m-2.5 rounded-md p-2.5 text-slate-400 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:text-white">
        <span className="sr-only">Theme</span>
        <Current aria-hidden className="size-6" />
      </MenuButton>
      <MenuItems transition anchor="bottom end" className="z-50 mt-2 w-36 rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-900/5 transition data-closed:scale-95 data-closed:opacity-0 data-enter:duration-100 dark:bg-slate-800 dark:ring-white/10">
        {themes.map((t) => (
          <MenuItem key={t.value}>
            <button type="button" onClick={() => setTheme(t.value)} className={cx('flex w-full items-center gap-2 px-3 py-2 text-sm data-focus:bg-slate-50 dark:data-focus:bg-white/5', theme === t.value ? 'font-semibold text-brand-600 dark:text-blue-400' : 'text-slate-700 dark:text-slate-200')}>
              <t.icon aria-hidden className="size-4" /> {t.label}
            </button>
          </MenuItem>
        ))}
      </MenuItems>
    </Menu>
  );
}

function UserMenu() {
  const { user, signOut } = useAuth();
  const navigate = useNavigate();
  const initials = (user?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  return (
    <Menu as="div" className="relative">
      <MenuButton className="-m-1.5 flex items-center gap-2 rounded-md p-1.5 focus-visible:outline-2 focus-visible:outline-brand-600">
        <span className="sr-only">Open user menu</span>
        <span className="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{initials}</span>
        <span className="hidden lg:flex lg:items-center">
          <span className="text-sm/6 font-semibold text-slate-900 dark:text-white">{user?.name}</span>
          <ChevronDownIcon aria-hidden className="ml-1 size-5 text-slate-400" />
        </span>
      </MenuButton>
      <MenuItems transition anchor="bottom end" className="z-50 mt-2.5 w-56 rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-900/5 transition data-closed:scale-95 data-closed:opacity-0 data-enter:duration-100 dark:bg-slate-800 dark:ring-white/10">
        <div className="border-b border-slate-100 px-3 py-2.5 dark:border-white/10">
          <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{user?.name}</p>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user?.email}</p>
          {(user?.role_label || user?.role) && <p className="mt-1 text-xs text-brand-600 dark:text-blue-400">{String(user?.role_label ?? user?.role)}</p>}
        </div>
        <MenuItem>
          <Link to="/settings" className="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 data-focus:bg-slate-50 dark:text-slate-200 dark:data-focus:bg-white/5">
            <UserCircleIcon aria-hidden className="size-4 text-slate-400" /> Settings
          </Link>
        </MenuItem>
        <MenuItem>
          <button type="button" onClick={() => { signOut(); navigate('/login'); }} className="flex w-full items-center gap-2 px-3 py-2 text-sm text-slate-700 data-focus:bg-slate-50 dark:text-slate-200 dark:data-focus:bg-white/5">
            <ArrowRightStartOnRectangleIcon aria-hidden className="size-4 text-slate-400" /> Sign out
          </button>
        </MenuItem>
      </MenuItems>
    </Menu>
  );
}

function Breadcrumbs() {
  const { pathname } = useLocation();
  const item = findItem(pathname);
  if (!item || item.to === '/') return null;
  return (
    <nav aria-label="Breadcrumb" className="mb-4 flex">
      <ol role="list" className="flex items-center gap-2 text-sm">
        <li><Link to="/" className="text-slate-400 hover:text-slate-500"><HomeIcon aria-hidden className="size-4" /><span className="sr-only">Dashboard</span></Link></li>
        <li className="flex items-center gap-2 text-slate-400"><ChevronRightIcon aria-hidden className="size-4" />{item.section}</li>
        <li className="flex items-center gap-2"><ChevronRightIcon aria-hidden className="size-4 text-slate-400" />
          <Link to={item.to} aria-current="page" className="font-medium text-slate-600 hover:text-slate-800 dark:text-slate-300 dark:hover:text-white">{item.label}</Link>
        </li>
      </ol>
    </nav>
  );
}

export function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const { pathname } = useLocation();
  const item = findItem(pathname);

  useEffect(() => {
    document.title = item ? `${item.label} · WorkSuite AI Studio` : 'WorkSuite AI Studio';
  }, [item]);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPaletteOpen(true); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  return (
    <div className="min-h-full">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:shadow">Skip to content</a>

      <Dialog open={mobileOpen} onClose={setMobileOpen} className="relative z-50 lg:hidden">
        <DialogBackdrop transition className="fixed inset-0 bg-slate-900/60 transition-opacity duration-300 ease-linear data-closed:opacity-0" />
        <div className="fixed inset-0 flex">
          <DialogPanel transition className="relative mr-16 flex w-full max-w-xs flex-1 transform transition duration-300 ease-in-out data-closed:-translate-x-full">
            <TransitionChild>
              <div className="absolute top-0 left-full flex w-16 justify-center pt-5 duration-300 ease-in-out data-closed:opacity-0">
                <button type="button" onClick={() => setMobileOpen(false)} className="-m-2.5 p-2.5">
                  <span className="sr-only">Close sidebar</span>
                  <XMarkIcon aria-hidden className="size-6 text-white" />
                </button>
              </div>
            </TransitionChild>
            <Sidebar onNavigate={() => setMobileOpen(false)} />
          </DialogPanel>
        </div>
      </Dialog>

      <div className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col">
        <Sidebar />
      </div>

      <div className="lg:pl-64">
        <div className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-4 border-b border-slate-200 bg-white/90 px-4 shadow-xs backdrop-blur sm:gap-x-6 sm:px-6 lg:px-8 dark:border-white/10 dark:bg-slate-900/90 dark:shadow-none">
          <button type="button" onClick={() => setMobileOpen(true)} className="-m-2.5 p-2.5 text-slate-700 lg:hidden dark:text-slate-400">
            <span className="sr-only">Open sidebar</span>
            <Bars3Icon aria-hidden className="size-6" />
          </button>
          <div aria-hidden className="h-6 w-px bg-slate-900/10 lg:hidden dark:bg-white/10" />
          <div className="flex flex-1 items-center gap-x-4 self-stretch lg:gap-x-6">
            <button
              type="button"
              onClick={() => setPaletteOpen(true)}
              className="flex flex-1 items-center gap-2 text-left text-sm text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
            >
              <MagnifyingGlassIcon aria-hidden className="size-5" />
              <span>Search pages…</span>
              <kbd className="ml-2 hidden rounded border border-slate-200 px-1.5 font-sans text-xs text-slate-400 sm:inline dark:border-white/10">⌘K</kbd>
            </button>
            <div className="flex items-center gap-x-4 lg:gap-x-6">
              <ThemeMenu />
              <NotificationsBell />
              <div aria-hidden className="hidden lg:block lg:h-6 lg:w-px lg:bg-slate-900/10 dark:lg:bg-white/10" />
              <UserMenu />
            </div>
          </div>
        </div>

        <main id="main" className="py-6 sm:py-8">
          <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <Breadcrumbs />
            <div key={pathname} className="animate-fade-in">
              <Outlet />
            </div>
          </div>
        </main>
      </div>
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </div>
  );
}
