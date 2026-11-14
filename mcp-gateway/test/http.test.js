// The HTTP gateway (ADR-0024) driven by the official SDK client over Streamable HTTP, against a fake ERP API.
import assert from 'node:assert/strict';
import { createServer, request as httpRequest } from 'node:http';
import { after, before, test } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { createHttpGateway } from '../src/http.js';

const TOKEN_A = 'tok_http_user_a_0123456789';
const TOKEN_B = 'tok_http_user_b_0123456789';
let api;
let gateway;
let apiUrl;
let gatewayUrl;
let seen = [];

before(async () => {
  api = createServer((req, res) => {
    seen.push({ url: req.url, auth: req.headers.authorization });
    const send = (status, body) => {
      res.writeHead(status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(body));
    };
    if (![`Bearer ${TOKEN_A}`, `Bearer ${TOKEN_B}`].includes(req.headers.authorization)) return send(401, { message: 'Unauthenticated.' });
    if (req.url === '/api/v1/mcp/context') return send(200, { data: { user_id: 'usr_1', branches: ['branch12345'] } });
    send(200, { data: [{ id: 'cus_abcd1234', name_en: 'Nile Co' }], meta: { total: 1 } });
  });
  await new Promise((resolve) => api.listen(0, '127.0.0.1', resolve));
  apiUrl = `http://127.0.0.1:${api.address().port}`;
  gateway = createHttpGateway({ API_BASE_URL: apiUrl });
  await new Promise((resolve) => gateway.listen(0, '127.0.0.1', resolve));
  gatewayUrl = `http://127.0.0.1:${gateway.address().port}`;
});

after(async () => {
  await new Promise((resolve) => gateway.close(resolve));
  await new Promise((resolve) => api.close(resolve));
});

async function connect(token) {
  const transport = new StreamableHTTPClientTransport(new URL(`${gatewayUrl}/mcp`), { requestInit: { headers: { Authorization: `Bearer ${token}` } } });
  const client = new Client({ name: 'http-test', version: '1.0.0' });
  await client.connect(transport);
  return client;
}

test('health answers without a token and reveals nothing', async () => {
  const response = await fetch(`${gatewayUrl}/health`);
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), { status: 'ok' });
});

test('a request without a bearer token is refused with 401', async () => {
  const response = await fetch(`${gatewayUrl}/mcp`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' }, body: '{}' });
  assert.equal(response.status, 401);
  assert.equal(response.headers.get('www-authenticate'), 'Bearer');
});

test('browser origins, GET and other paths are refused', async () => {
  const headers = { Authorization: `Bearer ${TOKEN_A}`, 'Content-Type': 'application/json' };
  assert.equal((await fetch(`${gatewayUrl}/mcp`, { method: 'POST', headers: { ...headers, Origin: 'https://evil.example' }, body: '{}' })).status, 403);
  assert.equal((await fetch(`${gatewayUrl}/mcp`, { method: 'GET', headers })).status, 405);
  assert.equal((await fetch(`${gatewayUrl}/other`, { method: 'POST', headers, body: '{}' })).status, 404);
});

test('the SDK client lists the 15 read-only tools and calls one with the caller\'s own token', async () => {
  seen = [];
  const client = await connect(TOKEN_A);
  try {
    const { tools } = await client.listTools();
    assert.equal(tools.length, 15);
    assert.ok(tools.every((t) => t.annotations.readOnlyHint === true));
    const result = await client.callTool({ name: 'erp_customers_list', arguments: { search: 'nile' } });
    assert.ok(!result.isError, JSON.stringify(result.content));
    assert.match(result.content[0].text, /Nile Co/);
    assert.ok(seen.length > 0 && seen.every((r) => r.auth === `Bearer ${TOKEN_A}`));
  } finally {
    await client.close();
  }
});

test('two users on the same gateway never share a token', async () => {
  seen = [];
  const a = await connect(TOKEN_A);
  const b = await connect(TOKEN_B);
  try {
    await a.callTool({ name: 'erp_customers_list', arguments: {} });
    await b.callTool({ name: 'erp_customers_list', arguments: {} });
    assert.ok(seen.some((r) => r.auth === `Bearer ${TOKEN_A}`));
    assert.ok(seen.some((r) => r.auth === `Bearer ${TOKEN_B}`));
  } finally {
    await a.close();
    await b.close();
  }
});

test('a wrong token reaches the ERP and comes back as the stable unauthenticated error', async () => {
  const client = await connect('tok_http_wrong_0123456789');
  try {
    const result = await client.callTool({ name: 'erp_customers_list', arguments: {} });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /unauthenticated/);
  } finally {
    await client.close();
  }
});

test('a malformed request target is a 400 and the gateway keeps serving', async () => {
  const { port } = gateway.address();
  for (const target of ['//[', '//%', '/mcp?%E0%A4%A']) {
    const status = await new Promise((resolve, reject) => {
      const request = httpRequest({ host: '127.0.0.1', port, method: 'POST', path: target }, (res) => {
        res.resume();
        resolve(res.statusCode);
      });
      request.on('error', reject);
      request.end('{}');
    });
    assert.ok(status >= 400 && status < 500, `${target} -> ${status}`);
  }
  const health = await fetch(`${gatewayUrl}/health`);
  assert.equal(health.status, 200, 'the gateway must still be up');
});
