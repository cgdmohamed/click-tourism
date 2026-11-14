#!/usr/bin/env node
// Click Tourism ERP - read-only MCP gateway over Streamable HTTP (ADR-0024).
// For clients that need a REMOTE MCP server: the OpenAI Responses API / ChatGPT and remote Claude
// clients. Stateless: every request carries the user's own MCP token as `Authorization: Bearer`,
// the gateway never stores it and calls /api/v1 with it, so the ERP's permissions, branch scope,
// masking, budgets and audit apply exactly as for the stdio gateway. Serve it behind HTTPS only.

import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { loadConfig } from './config.js';
import { createMcpServer } from './server.js';

const MAX_BODY_BYTES = 1024 * 1024;
const MAX_CONFIGS = 500;
const CONFIG_IDLE_MS = 60 * 60 * 1000;

/** Per-token config cache so the circuit breaker and branch list survive between requests. Keyed by a hash, never the token. */
function configCache(env) {
  const entries = new Map();
  return (token, branchId) => {
    const key = createHash('sha256').update(`${token}|${branchId ?? ''}`).digest('hex');
    const now = Date.now();
    for (const [k, v] of entries) if (now - v.last > CONFIG_IDLE_MS) entries.delete(k);
    let entry = entries.get(key);
    if (!entry) {
      if (entries.size >= MAX_CONFIGS) entries.delete(entries.keys().next().value);
      entry = { config: loadConfig({ ...env, MCP_TOKEN: token, ...(branchId ? { MCP_BRANCH_ID: branchId } : {}) }), last: now };
      entries.set(key, entry);
    }
    entry.last = now;
    return entry.config;
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error('Request too large.'), { status: 413 }));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(Object.assign(new Error('The body is not valid JSON.'), { status: 400 }));
      }
    });
    req.on('error', reject);
  });
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', ...headers });
  res.end(JSON.stringify(body));
}

const rpcError = (message, code = -32000) => ({ jsonrpc: '2.0', error: { code, message }, id: null });

export function createHttpGateway(env = process.env) {
  // Validates API_BASE_URL once at start; the token comes with each request.
  loadConfig({ ...env, MCP_TOKEN: 'startup-check' });
  const configFor = configCache(env);
  const allowedOrigins = (env.MCP_ALLOWED_ORIGINS ?? '').split(',').map((v) => v.trim()).filter(Boolean);

  async function handle(req, res) {
    // A hostile request target such as `//[` makes URL parsing throw: that is a client error, never a crash.
    let url;
    try {
      url = new URL(req.url ?? '/', 'http://gateway.local');
    } catch {
      return send(res, 400, rpcError('Invalid request target.'));
    }

    if (url.pathname === '/health') return send(res, 200, { status: 'ok' });
    if (url.pathname !== '/mcp') return send(res, 404, rpcError('Not found.'));

    // Browsers send Origin; a remote MCP server must not be drivable from arbitrary web pages.
    const origin = req.headers.origin;
    if (origin && !allowedOrigins.includes(origin)) return send(res, 403, rpcError('Origin not allowed.'));
    if (req.method !== 'POST') return send(res, 405, rpcError('Use POST (this gateway is stateless).'), { Allow: 'POST' });

    const match = /^Bearer\s+(\S+)$/i.exec(req.headers.authorization ?? '');
    if (!match) return send(res, 401, rpcError('Send the ERP MCP token as "Authorization: Bearer <token>".'), { 'WWW-Authenticate': 'Bearer' });

    const branch = String(req.headers['x-branch-id'] ?? '').trim() || null;
    let config;
    try {
      config = configFor(match[1], branch);
    } catch (error) {
      return send(res, 400, rpcError(error.message));
    }

    let body;
    try {
      body = await readBody(req);
    } catch (error) {
      return send(res, error.status ?? 400, rpcError(error.message));
    }

    const server = createMcpServer(config);
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined, enableJsonResponse: true });
    res.on('close', () => {
      transport.close();
      server.close();
    });
    try {
      await server.connect(transport);
      await transport.handleRequest(req, res, body);
    } catch {
      if (!res.headersSent) send(res, 500, rpcError('Internal error.'));
    }
  }

  // Whatever goes wrong inside a request must not become an unhandled rejection that stops the process.
  return createServer((req, res) => {
    handle(req, res).catch(() => {
      if (!res.headersSent) send(res, 500, rpcError('Internal error.'));
      else res.end();
    });
  });
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  let gateway;
  try {
    gateway = createHttpGateway();
  } catch (error) {
    process.stderr.write(`click-tourism-mcp-http: ${error.message}\n`);
    process.exit(1);
  }
  const port = Number(process.env.PORT ?? 8787);
  gateway.listen(port, process.env.HOST ?? '0.0.0.0', () => process.stderr.write(`click-tourism-mcp-http: listening on :${port}/mcp\n`));
}
