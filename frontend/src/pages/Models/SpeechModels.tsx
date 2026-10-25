import { Link } from 'react-router-dom';
import { MicrophoneIcon, SpeakerWaveIcon } from '@heroicons/react/24/outline';
import { Badge, Card, CardHeader, DataTable, EmptyState, type Column } from '../../components/ui';
import { bytes } from '../../lib/format';
import { useAuth } from '../../lib/auth';
import { artifactsSize, StateBadge, usePackages, type PackageSummary } from '../engine/shared';

const SPEECH = ['asr', 'tts'];

/**
 * Speech models (Whisper and TTS voices) don't run in Ollama, so they never appear in the node model list above.
 * They live as Sinhala Engine packages; this lists them here so every model the studio holds is in one place.
 */
export default function SpeechModels() {
  const { can } = useAuth();
  const pkgs = usePackages();
  if (!can('engine.view')) return null;
  const rows = (pkgs.data ?? []).filter((p) => SPEECH.includes(p.task));

  const columns: Column<PackageSummary>[] = [
    {
      key: 'model', header: 'Model', cell: (p) => (
        <Link to={`/engine/packages/${p.id}`} className="block max-w-md min-w-0">
          <span className="flex items-center gap-2 font-medium text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-blue-400">
            {p.task === 'tts' ? <SpeakerWaveIcon aria-hidden className="size-4 shrink-0 text-slate-400" /> : <MicrophoneIcon aria-hidden className="size-4 shrink-0 text-slate-400" />}
            <span className="truncate">{p.base_model ?? p.package_id}</span>
          </span>
          <span className="text-xs text-slate-500 dark:text-slate-400">v{p.version} · {p.languages}</span>
        </Link>
      ),
    },
    { key: 'task', header: 'Type', className: 'whitespace-nowrap', cell: (p) => <Badge tone="purple">{p.task === 'asr' ? 'Speech → text' : 'Text → speech'}</Badge> },
    { key: 'runtime', header: 'Runtime', hideBelow: 'md', className: 'whitespace-nowrap', cell: (p) => <span>{p.runtime_name}{p.quantization ? ` · ${p.quantization}` : ''}</span> },
    { key: 'size', header: 'Size', align: 'right', hideBelow: 'sm', className: 'whitespace-nowrap', cell: (p) => <span className="tabular-nums">{bytes(artifactsSize(p.artifacts))}</span> },
    { key: 'licence', header: 'Licence', hideBelow: 'lg', className: 'whitespace-nowrap', cell: (p) => p.license_id },
    { key: 'state', header: 'Release state', className: 'whitespace-nowrap', cell: (p) => <StateBadge state={p.state} /> },
  ];

  return (
    <Card className="mt-6">
      <CardHeader
        title="Speech models"
        description="Sinhala speech recognition and voices. They run in the Sinhala Engine on devices, not in Ollama, so they are managed as packages."
        actions={<Link to="/engine/packages" className="text-sm font-semibold text-brand-600 dark:text-blue-400">All packages</Link>}
      />
      <DataTable
        columns={columns}
        rows={pkgs.data ? rows : undefined}
        rowKey={(p) => p.id}
        loading={pkgs.isLoading}
        error={pkgs.error}
        onRetry={() => pkgs.refetch()}
        empty={<EmptyState icon={MicrophoneIcon} title="No speech models yet" description="Register a Whisper or TTS package under Sinhala Engine → Packages." />}
      />
    </Card>
  );
}
