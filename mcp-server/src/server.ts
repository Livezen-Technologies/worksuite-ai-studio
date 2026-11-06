import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z, type ZodTypeAny } from 'zod';
import { AiStudioClient, ApiError } from './client.js';
import { API_REQUEST_TOOL, TOOLS, type ToolDef } from './tools.js';

const MAX_CHARS = 100_000;

function text(value: unknown) {
  let s = typeof value === 'string' ? value : JSON.stringify(value ?? null, null, 2);
  if (s.length > MAX_CHARS) s = s.slice(0, MAX_CHARS) + `\n… truncated (${s.length} chars). Narrow the query (page/per_page/filters).`;
  return { content: [{ type: 'text' as const, text: s }] };
}

function fail(e: unknown) {
  const msg = e instanceof ApiError
    ? `AI Studio API error ${e.status}: ${e.message}${e.data ? `\n${JSON.stringify(e.data, null, 2)}` : ''}`
    : `Error: ${e instanceof Error ? e.message + (e.cause ? ` (${(e.cause as Error).message ?? e.cause})` : '') : String(e)}`;
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function annotations(kind: ToolDef['kind'], title: string) {
  return {
    title,
    readOnlyHint: kind === 'read',
    destructiveHint: kind === 'destructive',
    idempotentHint: kind === 'read',
    openWorldHint: false,
  };
}

function inputShape(def: ToolDef): Record<string, ZodTypeAny> {
  const shape: Record<string, ZodTypeAny> = {};
  for (const [, key] of def.path.matchAll(/\{(\w+)\}/g)) {
    shape[key] = def.params?.[key] ?? z.number().int().positive().describe(`${key}`);
  }
  if (def.query) {
    shape.query = z.record(z.string(), z.union([z.string(), z.number(), z.boolean()])).optional()
      .describe('Optional query-string filters, e.g. {"search":"llama","page":1,"per_page":50}');
  }
  if (def.body !== undefined) {
    shape.data = z.record(z.string(), z.any()).optional().describe(`Request body. Fields: ${def.body}`);
  }
  return shape;
}

function fillPath(path: string, args: Record<string, unknown>) {
  return path.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(String(args[k])));
}

export function buildServer(client: AiStudioClient, opts: { readOnly?: boolean } = {}) {
  const server = new McpServer(
    { name: 'worksuite-ai-studio', version: '1.0.0' },
    {
      instructions:
        'Tools for managing a WorkSuite AI Studio install: Ollama servers and models, chat, API keys and logs, users/roles, ' +
        'knowledge bases, agents, datasets and training, storage and backups, compute allocations and the Sinhala engine. ' +
        'Tools act as one AI Studio user and are limited by that user\'s permissions. Start with whoami or dashboard_get. ' +
        'Look up ids with the *_list tools before calling *_get/*_update/*_delete. Confirm with the user before destructive tools.',
    },
  );

  for (const def of TOOLS) {
    if (opts.readOnly && def.kind !== 'read') continue;
    server.registerTool(
      def.name,
      { title: def.title, description: def.description, inputSchema: inputShape(def), annotations: annotations(def.kind, def.title) },
      async (args: Record<string, unknown>) => {
        try {
          const path = fillPath(def.path, args);
          const data = args.data as Record<string, unknown> | undefined;
          if (def.stream) return text(await client.stream(path, data ?? {}));
          const body = def.method === 'GET' ? undefined : (data ?? {});
          return text(await client.request(def.method, path, { query: args.query as Record<string, unknown> | undefined, body }));
        } catch (e) {
          return fail(e);
        }
      },
    );
  }

  const methods = opts.readOnly ? (['GET'] as const) : (['GET', 'POST', 'PUT', 'DELETE'] as const);
  server.registerTool(
    API_REQUEST_TOOL.name,
    {
      title: API_REQUEST_TOOL.title,
      description: API_REQUEST_TOOL.description + (opts.readOnly ? ' (Read-only mode: GET only.)' : ''),
      inputSchema: {
        method: z.enum(methods as unknown as [string, ...string[]]).describe('HTTP method'),
        path: z.string().regex(/^\/(?!auth\/)[\w\-/.]*$/, 'Path under /api, e.g. /servers/3 (auth routes are not allowed)').describe('Path relative to /api'),
        query: z.record(z.string(), z.union([z.string(), z.number(), z.boolean()])).optional().describe('Query-string parameters'),
        data: z.record(z.string(), z.any()).optional().describe('JSON body for POST/PUT/DELETE'),
      },
      annotations: { title: API_REQUEST_TOOL.title, readOnlyHint: !!opts.readOnly, destructiveHint: !opts.readOnly, openWorldHint: false },
    },
    async ({ method, path, query, data }) => {
      try {
        if (path.includes('..')) throw new Error('Path may not contain "..".');
        return text(await client.request(method, path, { query, body: method === 'GET' ? undefined : (data ?? {}) }));
      } catch (e) {
        return fail(e);
      }
    },
  );

  return server;
}
