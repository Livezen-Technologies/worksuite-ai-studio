import { AdjustmentsHorizontalIcon, ArrowDownTrayIcon, ArrowPathIcon, Bars3Icon } from '@heroicons/react/24/outline';
import { IconButton, Select, Skeleton } from '../../components/ui';
import type { ModelOption } from './shared';

/** Title, model picker and conversation actions above the messages. */
export function ChatHeader({ title, systemPrompt, modelsLoading, modelKey, onModelChange, modelOptions, canRegenerate, canExport, onOpenSidebar, onOpenSettings, onRegenerate, onExport }: {
  title: string;
  systemPrompt: string;
  modelsLoading: boolean;
  modelKey: string;
  onModelChange: (key: string) => void;
  modelOptions: ModelOption[];
  canRegenerate: boolean;
  canExport: boolean;
  onOpenSidebar: () => void;
  onOpenSettings: () => void;
  onRegenerate: () => void;
  onExport: () => void;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-3 py-2.5 sm:px-4 dark:border-white/10">
      <IconButton label="Conversations" icon={Bars3Icon} className="md:hidden" onClick={onOpenSidebar} />
      <div className="min-w-0 flex-1">
        <h1 className="truncate text-sm font-semibold text-slate-900 dark:text-white">{title}</h1>
        {systemPrompt && <p className="truncate text-xs text-slate-500 dark:text-slate-400">System: {systemPrompt}</p>}
      </div>
      <div className="order-last w-full sm:order-none sm:w-64">
        {modelsLoading ? <Skeleton className="h-9 w-full" /> : (
          <Select
            aria-label="Model"
            value={modelKey}
            onChange={(e) => onModelChange(e.target.value)}
            options={modelOptions}
            placeholder={modelOptions.length ? undefined : 'No models — pull one first'}
          />
        )}
      </div>
      <div className="flex items-center gap-0.5">
        <IconButton label="Conversation settings" icon={AdjustmentsHorizontalIcon} onClick={onOpenSettings} />
        <IconButton label="Regenerate last reply" icon={ArrowPathIcon} disabled={!canRegenerate} onClick={onRegenerate} />
        <IconButton label="Export as JSON" icon={ArrowDownTrayIcon} disabled={!canExport} onClick={onExport} />
      </div>
    </div>
  );
}
