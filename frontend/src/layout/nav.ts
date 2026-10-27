import type { ComponentType, SVGProps } from 'react';
import {
  BeakerIcon, BellIcon, BookOpenIcon, ChartBarIcon, ChatBubbleLeftRightIcon, Cog6ToothIcon, CpuChipIcon,
  CircleStackIcon, ClipboardDocumentListIcon, CommandLineIcon, DocumentTextIcon, HomeIcon, KeyIcon,
  QueueListIcon, ServerStackIcon, MicrophoneIcon, CheckBadgeIcon, CubeIcon, ArrowsRightLeftIcon, PresentationChartLineIcon, ShieldCheckIcon, SignalIcon, SparklesIcon, TableCellsIcon, UsersIcon, AcademicCapIcon, Squares2X2Icon, ScaleIcon,
} from '@heroicons/react/24/outline';

export interface NavItem { label: string; to: string; icon: ComponentType<SVGProps<SVGSVGElement>>; perm: string; description: string }
export interface NavSection { heading: string; items: NavItem[] }

/** The same sections, routes and permissions as OllamaHub 1.3, so bookmarks and roles carry over. */
export const NAV: NavSection[] = [
  { heading: 'Overview', items: [
    { label: 'Dashboard', to: '/', icon: HomeIcon, perm: 'dashboard.view', description: 'Fleet health, usage and activity at a glance' },
  ] },
  { heading: 'Infrastructure', items: [
    { label: 'Servers', to: '/servers', icon: ServerStackIcon, perm: 'servers.view', description: 'Ollama nodes and providers' },
    { label: 'Storage', to: '/storage', icon: CircleStackIcon, perm: 'storage.view', description: 'Storage servers, mounts and S3 buckets' },
    { label: 'Node App', to: '/node-app', icon: CommandLineIcon, perm: 'servers.view', description: 'Download the node app and the setup guide' },
    { label: 'Models', to: '/models', icon: CpuChipIcon, perm: 'models.view', description: 'Pull, load and configure models' },
    { label: 'Monitoring', to: '/monitoring', icon: SignalIcon, perm: 'monitoring.view', description: 'Live CPU, memory, GPU and health' },
    { label: 'Compute Allocations', to: '/compute', icon: ScaleIcon, perm: 'compute.manage', description: 'Give users CPU/GPU capacity on nodes' },
    { label: 'Compute Usage', to: '/compute/usage', icon: ArrowsRightLeftIcon, perm: 'compute.manage', description: 'Who uses what; job placement' },
    { label: 'Queue', to: '/queue', icon: QueueListIcon, perm: 'queues.view', description: 'Background jobs and schedules' },
  ] },
  { heading: 'My Workspace', items: [
    { label: 'My Workspace', to: '/portal', icon: Squares2X2Icon, perm: 'portal.use', description: 'Your allocations, datasets and jobs' },
    { label: 'My Datasets', to: '/portal/datasets', icon: TableCellsIcon, perm: 'portal.use', description: 'Upload your own training data' },
    { label: 'My Training Jobs', to: '/portal/jobs', icon: AcademicCapIcon, perm: 'portal.use', description: 'Train models on your allocated nodes' },
  ] },
  { heading: 'SinLama', items: [
    { label: 'Model Studio', to: '/studio', icon: SparklesIcon, perm: 'models.view', description: 'Build custom models from a base' },
    { label: 'Datasets', to: '/datasets', icon: TableCellsIcon, perm: 'models.view', description: 'Instruction datasets for fine-tuning' },
    { label: 'Training', to: '/training', icon: AcademicCapIcon, perm: 'models.view', description: 'LoRA / QLoRA training jobs and kits' },
  ] },
  { heading: 'Sinhala Engine', items: [
    { label: 'Engine Overview', to: '/engine', icon: PresentationChartLineIcon, perm: 'engine.view', description: 'Active packs, pipeline and gates' },
    { label: 'Evaluations', to: '/engine/evaluations', icon: CheckBadgeIcon, perm: 'engine.view', description: 'Evaluation reports and release gates' },
    { label: 'Approvals', to: '/engine/approvals', icon: ShieldCheckIcon, perm: 'engine.view', description: 'Items waiting for your approval' },
    { label: 'Packages', to: '/engine/packages', icon: CubeIcon, perm: 'engine.view', description: 'Signed model packages and lifecycle' },
    { label: 'Bindings', to: '/engine/bindings', icon: ArrowsRightLeftIcon, perm: 'engine.view', description: 'Active pack per task and device profile' },
  ] },
  { heading: 'AI Workspace', items: [
    { label: 'Chat Playground', to: '/chat', icon: ChatBubbleLeftRightIcon, perm: 'chat.use', description: 'Talk to any model' },
    { label: 'Speech to Text', to: '/speech', icon: MicrophoneIcon, perm: 'chat.use', description: 'Record or upload Sinhala speech and get the text' },
    { label: 'Prompt Library', to: '/prompts', icon: DocumentTextIcon, perm: 'prompts.view', description: 'Reusable prompts and templates' },
    { label: 'Knowledge Base', to: '/knowledge', icon: BookOpenIcon, perm: 'knowledge.view', description: 'Documents for retrieval' },
    { label: 'AI Agents', to: '/agents', icon: BeakerIcon, perm: 'agents.view', description: 'Configured assistants' },
  ] },
  { heading: 'Gateway', items: [
    { label: 'API Keys', to: '/api-keys', icon: KeyIcon, perm: 'apikeys.view', description: 'Keys for the /api/v1 gateway' },
    { label: 'API Logs', to: '/api-logs', icon: CommandLineIcon, perm: 'logs.view', description: 'Every gateway request' },
    { label: 'Analytics', to: '/analytics', icon: ChartBarIcon, perm: 'analytics.view', description: 'Tokens, latency and usage trends' },
  ] },
  { heading: 'Administration', items: [
    { label: 'Users', to: '/users', icon: UsersIcon, perm: 'users.view', description: 'People who can sign in' },
    { label: 'Roles', to: '/roles', icon: ShieldCheckIcon, perm: 'roles.view', description: 'Roles and permissions' },
    { label: 'Audit Logs', to: '/audit', icon: ClipboardDocumentListIcon, perm: 'audit.view', description: 'Who changed what' },
    { label: 'Notifications', to: '/notifications', icon: BellIcon, perm: 'notifications.view', description: 'Alerts and broadcasts' },
    { label: 'Settings', to: '/settings', icon: Cog6ToothIcon, perm: 'settings.view', description: 'Platform configuration' },
  ] },
];

export const ALL_ITEMS = NAV.flatMap((s) => s.items.map((i) => ({ ...i, section: s.heading })));
export function findItem(pathname: string) {
  return ALL_ITEMS.filter((i) => (i.to === '/' ? pathname === '/' : pathname === i.to || pathname.startsWith(i.to + '/'))).sort((a, b) => b.to.length - a.to.length)[0];
}
export { CircleStackIcon as LogoIcon };
