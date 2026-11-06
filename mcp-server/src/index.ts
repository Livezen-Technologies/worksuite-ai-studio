#!/usr/bin/env node
/**
 * WorkSuite AI Studio MCP server.
 *
 *   node dist/index.js          stdio, for Claude Desktop / Claude Code / any local MCP client
 *   node dist/index.js --http   Streamable HTTP at /mcp, for claude.ai and ChatGPT connectors
 */
import { timingSafeEqual } from 'node:crypto';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import express, { type Request, type Response, type NextFunction } from 'express';
import { AiStudioClient, configFromEnv } from './client.js';
import { buildServer } from './server.js';

const readOnly = /^(1|true|yes)$/i.test(process.env.AISTUDIO_MCP_READ_ONLY ?? '');
const client = new AiStudioClient(configFromEnv());

function safeEqual(a: string, b: string) {
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}

async function startHttp() {
  const secret = process.env.MCP_AUTH_SECRET ?? '';
  if (secret.length < 24) {
    throw new Error('MCP_AUTH_SECRET (at least 24 characters) is required in HTTP mode: anyone with the URL could otherwise manage AI Studio.');
  }
  const port = Number(process.env.PORT ?? 8787);
  const app = express();
  app.disable('x-powered-by');
  app.use(express.json({ limit: '25mb' }));

  // Accept the secret as a Bearer token or as the last path segment (/mcp/<secret>) for
  // connectors that cannot send custom headers.
  const auth = (req: Request, res: Response, next: NextFunction) => {
    const header = req.headers.authorization ?? '';
    const given = header.startsWith('Bearer ') ? header.slice(7) : String(req.params.secret ?? '');
    if (given && safeEqual(given, secret)) return next();
    res.status(401).json({ jsonrpc: '2.0', error: { code: -32001, message: 'Unauthorized' }, id: null });
  };

  // Stateless: a fresh server + transport per request, so it scales and survives restarts.
  const handle = async (req: Request, res: Response) => {
    const server = buildServer(client, { readOnly });
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    res.on('close', () => { transport.close(); server.close(); });
    try {
      await server.connect(transport);
      await transport.handleRequest(req, res, req.body);
    } catch (e) {
      console.error('MCP request failed:', e);
      if (!res.headersSent) res.status(500).json({ jsonrpc: '2.0', error: { code: -32603, message: 'Internal error' }, id: null });
    }
  };
  const notAllowed = (_req: Request, res: Response) =>
    res.status(405).json({ jsonrpc: '2.0', error: { code: -32000, message: 'Method not allowed (stateless server).' }, id: null });

  for (const path of ['/mcp', '/mcp/:secret']) {
    app.post(path, auth, handle);
    app.get(path, auth, notAllowed);
    app.delete(path, auth, notAllowed);
  }
  app.get('/healthz', (_req, res) => { res.json({ ok: true }); });

  app.listen(port, () => {
    console.error(`AI Studio MCP server (HTTP${readOnly ? ', read-only' : ''}) on :${port}/mcp -> ${client.baseUrl}`);
  });
}

async function startStdio() {
  const server = buildServer(client, { readOnly });
  await server.connect(new StdioServerTransport());
  console.error(`AI Studio MCP server (stdio${readOnly ? ', read-only' : ''}) -> ${client.baseUrl}`);
}

(process.argv.includes('--http') ? startHttp() : startStdio()).catch((e) => {
  console.error(e instanceof Error ? e.message : e);
  process.exit(1);
});
