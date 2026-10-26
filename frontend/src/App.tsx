import { Component, lazy, Suspense, type ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom';
import { ExclamationTriangleIcon, LockClosedIcon } from '@heroicons/react/24/outline';
import { useAuth } from './lib/auth';
import { AppShell } from './layout/AppShell';
import { Button, EmptyState, Spinner } from './components/ui';
import Login from './pages/Login';

const pages = {
  '/': lazy(() => import('./pages/Dashboard')),
  '/servers': lazy(() => import('./pages/Servers')),
  '/node-app': lazy(() => import('./pages/NodeApp')),
  '/models': lazy(() => import('./pages/Models')),
  '/monitoring': lazy(() => import('./pages/Monitoring')),
  '/queue': lazy(() => import('./pages/Queue')),
  '/studio': lazy(() => import('./pages/Studio')),
  '/datasets': lazy(() => import('./pages/Datasets')),
  '/training': lazy(() => import('./pages/Training')),
  '/portal': lazy(() => import('./pages/portal/Overview')),
  '/portal/datasets': lazy(() => import('./pages/portal/Datasets')),
  '/portal/jobs': lazy(() => import('./pages/portal/Jobs')),
  '/compute': lazy(() => import('./pages/compute/Allocations')),
  '/compute/usage': lazy(() => import('./pages/compute/Usage')),
  '/engine': lazy(() => import('./pages/engine/Overview')),
  '/engine/evaluations': lazy(() => import('./pages/engine/Evaluations')),
  '/engine/approvals': lazy(() => import('./pages/engine/Approvals')),
  '/engine/packages': lazy(() => import('./pages/engine/Packages')),
  '/engine/bindings': lazy(() => import('./pages/engine/Bindings')),
  '/chat': lazy(() => import('./pages/Chat')),
  '/speech': lazy(() => import('./pages/Speech')),
  '/prompts': lazy(() => import('./pages/Prompts')),
  '/knowledge': lazy(() => import('./pages/Knowledge')),
  '/agents': lazy(() => import('./pages/Agents')),
  '/api-keys': lazy(() => import('./pages/ApiKeys')),
  '/api-logs': lazy(() => import('./pages/ApiLogs')),
  '/analytics': lazy(() => import('./pages/Analytics')),
  '/users': lazy(() => import('./pages/Users')),
  '/roles': lazy(() => import('./pages/Roles')),
  '/audit': lazy(() => import('./pages/Audit')),
  '/notifications': lazy(() => import('./pages/Notifications')),
  '/settings': lazy(() => import('./pages/Settings')),
} as const;

const perms: Record<keyof typeof pages, string> = {
  '/': 'dashboard.view', '/servers': 'servers.view', '/node-app': 'servers.view', '/models': 'models.view', '/monitoring': 'monitoring.view', '/queue': 'queues.view',
  '/studio': 'models.view', '/datasets': 'models.view', '/training': 'models.view', '/portal': 'portal.use', '/portal/datasets': 'portal.use', '/portal/jobs': 'portal.use', '/compute': 'compute.manage', '/compute/usage': 'compute.manage', '/engine': 'engine.view', '/engine/evaluations': 'engine.view', '/engine/approvals': 'engine.view', '/engine/packages': 'engine.view', '/engine/bindings': 'engine.view',
  '/chat': 'chat.use', '/speech': 'chat.use', '/prompts': 'prompts.view',
  '/knowledge': 'knowledge.view', '/agents': 'agents.view', '/api-keys': 'apikeys.view', '/api-logs': 'logs.view', '/analytics': 'analytics.view',
  '/users': 'users.view', '/roles': 'roles.view', '/audit': 'audit.view', '/notifications': 'notifications.view', '/settings': 'settings.view',
};

/** OllamaHub 1.x had /servers/:id; the detail is now a dialog on /servers?server=:id. */
function ServerDeepLink() {
  const { id } = useParams();
  return <Navigate to={`/servers?server=${encodeURIComponent(id ?? '')}`} replace />;
}

function PageLoading() {
  return <div className="flex min-h-[40vh] items-center justify-center text-slate-400"><Spinner className="size-6" /></div>;
}

class PageBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null };
  static getDerivedStateFromError(error: Error) { return { error }; }
  componentDidCatch(error: Error) { console.error('Page error:', error); }
  render() {
    if (!this.state.error) return this.props.children;
    return (
      <EmptyState
        icon={ExclamationTriangleIcon}
        title="Something went wrong on this page"
        description={this.state.error.message}
        action={
          <div className="flex gap-2">
            <Button onClick={() => this.setState({ error: null })}>Try again</Button>
            <Button variant="primary" onClick={() => window.location.reload()}>Reload</Button>
          </div>
        }
      />
    );
  }
}

function Guard({ perm, children }: { perm: string; children: ReactNode }) {
  const { can } = useAuth();
  if (!can(perm)) {
    return <EmptyState icon={LockClosedIcon} title="You don't have access to this page" description="Ask an administrator to give your role this permission." />;
  }
  return <PageBoundary><Suspense fallback={<PageLoading />}>{children}</Suspense></PageBoundary>;
}

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <div className="flex h-full items-center justify-center text-slate-400"><Spinner className="size-7" /></div>;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  return <>{children}</>;
}

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      {/* Old URLs from OllamaHub 1.x */}
      <Route path="/apikeys" element={<Navigate to="/api-keys" replace />} />
      <Route path="/apilogs" element={<Navigate to="/api-logs" replace />} />
      <Route path="/dashboard" element={<Navigate to="/" replace />} />
      <Route element={<RequireAuth><AppShell /></RequireAuth>}>
        <Route path="/servers/:id" element={<ServerDeepLink />} />
        {(Object.keys(pages) as (keyof typeof pages)[]).map((path) => {
          const Page = pages[path];
          return <Route key={path} path={path === '/' ? undefined : `${path}/*`} index={path === '/'} element={<Guard perm={perms[path]}><Page /></Guard>} />;
        })}
        <Route path="*" element={<EmptyState title="Page not found" description="The address may be mistyped, or the page has moved." />} />
      </Route>
    </Routes>
  );
}
