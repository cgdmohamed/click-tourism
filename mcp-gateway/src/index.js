#!/usr/bin/env node
// Click Tourism ERP - read-only MCP gateway, stages 1-2 (ADR-0016), stdio transport.
// No database, no filesystem access, no write tools. Calls /api/v1 with the user's own mcp:read token.
// For clients that need a remote server (OpenAI, remote Claude clients) use http.js instead.

import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { loadConfig } from './config.js';
import { createMcpServer } from './server.js';

let config;
try {
  config = loadConfig();
} catch (error) {
  process.stderr.write(`click-tourism-mcp: ${error.message}\n`);
  process.exit(1);
}

await createMcpServer(config).connect(new StdioServerTransport());
