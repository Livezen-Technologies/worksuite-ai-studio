import { ArrowUpTrayIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Meter, cx } from '../../components/ui';
import { bytes } from '../../lib/format';
import type { Upload } from './shared';

export default function FilePick({ label, hint, upload, error, disabled, onPick, onClear }: {
  label: string; hint: string; upload: Upload | null; error?: string; disabled?: boolean; onPick: () => void; onClear: () => void;
}) {
  const busy = upload !== null && !upload.blob && !upload.error;
  return (
    <div>
      <p className="text-sm/6 font-medium text-slate-900 dark:text-white">{label}</p>
      <div className={cx('mt-1.5 rounded-lg border border-dashed p-4', error ? 'border-red-400 dark:border-red-500/60' : 'border-slate-300 dark:border-white/15')}>
        {upload ? (
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{upload.file.name}</p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                  {bytes(upload.file.size)}
                  {upload.blob && <> · <span className="font-mono">{upload.blob.digest.slice(0, 19)}…</span></>}
                </p>
              </div>
              {upload.blob ? <Badge tone="green">On node</Badge> : upload.error ? <Badge tone="red">Failed</Badge> : <Badge tone="blue">{Math.round(upload.progress * 100)}%</Badge>}
              {!busy && (
                <button type="button" onClick={onClear} disabled={disabled} className="rounded p-1 text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-200" aria-label="Remove file">
                  <XMarkIcon className="size-4" />
                </button>
              )}
            </div>
            {busy && <Meter value={Math.round(upload.progress * 100)} label={upload.progress >= 1 ? 'Pushing to the node…' : 'Uploading…'} />}
            {upload.error && <p className="text-sm text-red-600 dark:text-red-400">{upload.error}</p>}
          </div>
        ) : (
          <div className="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-slate-500 dark:text-slate-400">{hint}</p>
            <Button variant="secondary" size="sm" icon={ArrowUpTrayIcon} onClick={onPick} disabled={disabled}>Choose file</Button>
          </div>
        )}
      </div>
      {error && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{error}</p>}
    </div>
  );
}
