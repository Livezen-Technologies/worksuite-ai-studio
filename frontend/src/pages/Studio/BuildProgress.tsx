import { Alert, Badge, Card, CardHeader, CodeBlock, Meter } from '../../components/ui';
import { bytes } from '../../lib/format';

export default function BuildProgress({ building, log, progress, result }: {
  building: boolean; log: string[]; progress: { completed: number; total: number } | null; result: { ok: boolean; text: string } | null;
}) {
  return (
    <Card>
      <CardHeader
        title="Build progress"
        actions={building ? <Badge tone="blue" dot>Building</Badge> : result ? <Badge tone={result.ok ? 'green' : 'red'}>{result.ok ? 'Done' : 'Failed'}</Badge> : null}
      />
      <div className="space-y-4 p-4 sm:p-6">
        {progress && building && <Meter value={progress.completed} max={progress.total} label={`${bytes(progress.completed)} of ${bytes(progress.total)}`} />}
        {log.length > 0 && <CodeBlock className="max-h-64 overflow-y-auto">{log.join('\n')}</CodeBlock>}
        {result && <Alert tone={result.ok ? 'success' : 'danger'}>{result.text}</Alert>}
      </div>
    </Card>
  );
}
