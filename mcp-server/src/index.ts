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
import { rateLimit } from 'express-rate-limit';
import { AiStudioClient, configFromEnv } from './client.js';
import { buildServer } from './server.js';

const readOnly = /^(1|true|yes)$/i.test(process.env.AISTUDIO_MCP_READ_ONLY ?? '');
const client = new AiStudioClient(configFromEnv());

function safeEqual(a: string, b: string) {
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}

async function startHttp() {
  // One secret per client (comma-separated) so each can be rotated or revoked on its own.
  const secrets = [process.env.MCP_AUTH_SECRET ?? '', ...(process.env.MCP_AUTH_SECRETS ?? '').split(',')]
    .map((x) => x.trim()).filter(Boolean);
  if (!secrets.length || secrets.some((x) => x.length < 24)) {
    throw new Error('Set MCP_AUTH_SECRET (or MCP_AUTH_SECRETS, comma-separated), each at least 24 characters: anyone reaching the URL could otherwise manage AI Studio.');
  }
  // A secret in the URL ends up in proxy and access logs, so it is opt-in (claude.ai / ChatGPT
  // connectors that cannot send headers need it; keep access_log off for that location).
  const allowUrlSecret = /^(1|true|yes)$/i.test(process.env.MCP_ALLOW_URL_SECRET ?? '');
  const port = Number(process.env.PORT ?? 8787);
  // Loopback by default: the reverse proxy (nginx) forwards to 127.0.0.1:8787.
  const host = process.env.HOST ?? '127.0.0.1';
  const app = express();
  app.disable('x-powered-by');
  // Only trust X-Forwarded-For when told to (e.g. TRUST_PROXY=loopback behind nginx), otherwise
  // a client could pick its own rate-limit key.
  const trustProxy = (process.env.TRUST_PROXY ?? '').trim();
  if (trustProxy) {
    app.set('trust proxy', /^\d+$/.test(trustProxy) ? Number(trustProxy) : /^true$/i.test(trustProxy) ? true : trustProxy);
  }
  const rpcError = (message: string) => ({ jsonrpc: '2.0', error: { code: -32029, message }, id: null });
  // 120 requests/minute per client IP on /mcp.
  const mcpLimiter = rateLimit({
    windowMs: 60_000, limit: 120, standardHeaders: 'draft-8', legacyHeaders: false,
    message: rpcError('Too many requests, slow down.'),
  });
  // Failed sign-ins: 20 per 15 minutes per IP, so secrets cannot be brute-forced.
  const authFailLimiter = rateLimit({
    windowMs: 15 * 60_000, limit: 20, standardHeaders: 'draft-8', legacyHeaders: false,
    requestWasSuccessful: (_req, res) => res.statusCode !== 401,
    skipSuccessfulRequests: true,
    message: rpcError('Too many failed authentication attempts, try again later.'),
  });
  app.use(express.json({ limit: '10mb' }));

  // Accept the secret as a Bearer token or as the last path segment (/mcp/<secret>) for
  // connectors that cannot send custom headers.
  const auth = (req: Request, res: Response, next: NextFunction) => {
    const header = req.headers.authorization ?? '';
    const given = header.startsWith('Bearer ') ? header.slice(7) : allowUrlSecret ? String(req.params.secret ?? '') : '';
    if (given && secrets.some((x) => safeEqual(given, x))) return next();
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

  for (const path of allowUrlSecret ? ['/mcp', '/mcp/:secret'] : ['/mcp']) {
    app.post(path, mcpLimiter, authFailLimiter, auth, handle);
    app.get(path, mcpLimiter, authFailLimiter, auth, notAllowed);
    app.delete(path, mcpLimiter, authFailLimiter, auth, notAllowed);
  }
  app.get('/healthz', (_req, res) => { res.json({ ok: true }); });

  app.listen(port, host, () => {
    console.error(`AI Studio MCP server (HTTP${readOnly ? ', read-only' : ''}) on ${host}:${port}/mcp -> ${client.baseUrl}`);
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
