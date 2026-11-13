// Stage 2 + leftovers of ADR-0016: the new tools, the second branch filter, the feature flag
// error, the budget error and the circuit breaker. Unit level, no network (fetch is injected).

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { loadConfig } from '../src/config.js';
import { TOOLS } from '../src/schemas.js';
import { maskTaxId } from '../src/sanitize.js';
import { buildPath, buildQuery, callTool, validateArguments } from '../src/tools.js';

const BRANCHES = ['branch12345', 'branch67890'];
// A fresh config object per use: the circuit breaker and the branch cache are kept per config object.
const fresh = (extra = {}) => ({ baseUrl: 'https://erp.test/api/v1', token: 'tok_secret_123', branchId: null, maskContacts: true, ...extra });

const reply = (status, body, headers = {}) => ({ ok: status >= 200 && status < 300, status, headers: new Headers(headers), json: async () => body });
const contextReply = (branches = BRANCHES) => reply(200, { data: { user_id: 'u1', branches } });

/** Answers GET /mcp/context itself and records every other call. */
function api(status, body, calls = [], branches = BRANCHES) {
  return async (url, init) => {
    if (String(url).endsWith('/mcp/context')) return contextReply(branches);
    calls.push({ url, init });
    return reply(status, body, status === 429 ? { 'retry-after': '12' } : {});
  };
}

const payloadOf = (result) => JSON.parse(result.content[0].text.split('<erp_data>\n')[1].split('\n</erp_data>')[0]);

test('get-by-id tools build their path from the validated id only', () => {
  assert.equal(buildPath('erp_customer_get', { id: 'cus_abcd1234' }), '/customers/cus_abcd1234');
  assert.equal(buildPath('erp_booking_get', { id: 'bkg_abcd1234' }), '/bookings/bkg_abcd1234');
  assert.equal(buildPath('erp_invoice_get', { id: 'inv_abcd1234' }), '/invoices/inv_abcd1234');
  assert.equal(buildPath('erp_payments_list', {}), '/payments');
  assert.equal(buildQuery('erp_customer_get', { id: 'cus_abcd1234', branch_id: 'branch12345' }), '');
  for (const bad of ['../payments', 'abc/../../x12', 'cus_abcd1234/pdf', 'cus abcd1234', 'short']) {
    for (const tool of ['erp_customer_get', 'erp_booking_get', 'erp_invoice_get']) {
      assert.notEqual(validateArguments(tool, { id: bad }), null, `${tool} ${bad}`);
    }
  }
  assert.match(validateArguments('erp_invoice_get', {}), /required/);
});

test('schemas of the new tools accept valid and reject invalid arguments', () => {
  assert.equal(validateArguments('erp_customers_list', { search: 'a', page: 2, per_page: 10 }), null);
  assert.match(validateArguments('erp_suppliers_list', { per_page: 26 }), /<= 25/);
  assert.equal(validateArguments('erp_payments_list', { customer_id: 'cus_abcd1234', from: '2026-01-01', to: '2026-01-31' }), null);
  assert.match(validateArguments('erp_payments_list', { from: 'yesterday' }), /format/);
  assert.equal(validateArguments('erp_expenses_list', { status: 'approved', search: 'taxi' }), null);
  assert.equal(validateArguments('erp_leads_list', { stage: 'new', status: 'open' }), null);
  assert.match(validateArguments('erp_leads_list', { owner: 'x' }), /additional/i);

  const statement = { party_type: 'customer', party_id: 'cus_abcd1234', from: '2026-01-01', to: '2026-01-31' };
  assert.equal(validateArguments('erp_party_statement', { ...statement, per_page: 100 }), null);
  assert.match(validateArguments('erp_party_statement', { party_type: 'customer', party_id: 'cus_abcd1234', from: '2026-01-01' }), /required/);
  assert.match(validateArguments('erp_party_statement', { ...statement, party_type: 'employee' }), /allowed values/);
  assert.equal(validateArguments('erp_aging', { type: 'ar', as_of: '2026-01-31' }), null);
  assert.match(validateArguments('erp_aging', { type: 'xx', as_of: '2026-01-31' }), /allowed values/);
  const range = { from: '2026-01-01', to: '2026-01-31' };
  assert.equal(validateArguments('erp_trial_balance', { ...range, adjustments: 'only', max_level: 3, pre_closing: true }), null);
  assert.match(validateArguments('erp_trial_balance', { ...range, cost_center_id: 'cc_abcd1234' }), /additional/i);
  assert.match(validateArguments('erp_trial_balance', { ...range, max_level: 21 }), /<= 20/);
  assert.equal(validateArguments('erp_income_statement', { ...range, compare_from: '2025-01-01', compare_to: '2025-01-31' }), null);
  assert.match(validateArguments('erp_income_statement', { from: '2026-01-01' }), /required/);
});

test('report tools send booleans as 1/0 and do not add a default page size', () => {
  assert.equal(
    buildQuery('erp_trial_balance', { from: '2026-01-01', to: '2026-01-31', pre_closing: true, adjustments: 'only' }),
    'from=2026-01-01&to=2026-01-31&pre_closing=1&adjustments=only',
  );
  assert.equal(buildQuery('erp_aging', { type: 'ap', as_of: '2026-03-31' }), 'type=ap&as_of=2026-03-31');
  assert.equal(buildQuery('erp_customers_list', {}), 'per_page=25');
});

test('every new tool calls its own route with GET and the tool header', async () => {
  const range = { from: '2026-01-01', to: '2026-01-31' };
  const samples = {
    erp_customers_list: [{}, '/customers?per_page=25'],
    erp_customer_get: [{ id: 'cus_abcd1234' }, '/customers/cus_abcd1234'],
    erp_suppliers_list: [{}, '/suppliers?per_page=25'],
    erp_booking_get: [{ id: 'bkg_abcd1234' }, '/bookings/bkg_abcd1234'],
    erp_invoice_get: [{ id: 'inv_abcd1234' }, '/invoices/inv_abcd1234'],
    erp_payments_list: [{}, '/payments?per_page=25'],
    erp_expenses_list: [{}, '/expenses?per_page=25'],
    erp_leads_list: [{}, '/leads?per_page=25'],
    erp_party_statement: [{ party_type: 'supplier', party_id: 'sup_abcd1234', ...range }, '/reports/party-statement?party_type=supplier&party_id=sup_abcd1234&from=2026-01-01&to=2026-01-31'],
    erp_aging: [{ type: 'ar', as_of: '2026-01-31' }, '/reports/aging?type=ar&as_of=2026-01-31'],
    erp_trial_balance: [range, '/reports/trial-balance?from=2026-01-01&to=2026-01-31'],
    erp_income_statement: [range, '/reports/income-statement?from=2026-01-01&to=2026-01-31'],
  };
  assert.equal(Object.keys(samples).length + 3, TOOLS.length, 'every tool except the three of stage 1 is covered');
  for (const [name, [args, expected]] of Object.entries(samples)) {
    const calls = [];
    const result = await callTool(fresh(), name, args, api(200, { data: [] }, calls));
    assert.equal(result.isError, undefined, name);
    assert.equal(calls.length, 1, name);
    assert.equal(calls[0].url, `https://erp.test/api/v1${expected}`, name);
    assert.equal(calls[0].init.method, 'GET');
    assert.equal(calls[0].init.headers['X-Mcp-Tool'], name);
  }
});

test('tax ids are masked like phones, and money integers pass through unchanged (AT-11)', async () => {
  assert.equal(maskTaxId('123-456-789'), '***89');
  const result = await callTool(
    fresh(),
    'erp_invoice_get',
    { id: 'inv_abcd1234' },
    api(200, { data: { id: 'inv_abcd1234', total_minor: 9007199254740, outstanding_minor: 0, currency: 'EGP', party: { tax_id: '123-456-789' }, memo: 'Paid in full' } }),
  );
  const payload = payloadOf(result);
  assert.equal(payload.data.total_minor, 9007199254740);
  assert.equal(payload.data.outstanding_minor, 0);
  assert.equal(payload.data.party.tax_id, '***89');
  assert.match(payload.data.memo, /^<untrusted_data field="memo">Paid in full/);
  assert.equal(payload.provenance.route, '/invoices/inv_abcd1234');
});

// ---- second branch filter ----------------------------------------------------------------------

test('a pinned branch must belong to the token user, otherwise nothing is sent', async () => {
  const calls = [];
  const result = await callTool(fresh({ branchId: 'foreignbr1' }), 'erp_bookings_list', {}, api(200, { data: [] }, calls));
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /"code":"branch_not_allowed"/);
  assert.equal(calls.length, 0, 'the tool route is never called');

  const allowed = await callTool(fresh({ branchId: 'branch67890' }), 'erp_bookings_list', {}, api(200, { data: [] }, calls));
  assert.equal(allowed.isError, undefined);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].init.headers['X-Branch-Id'], 'branch67890');
});

test('a branch passed as an argument is checked the same way', async () => {
  const calls = [];
  const result = await callTool(fresh(), 'erp_search', { q: 'abc', branch_id: 'foreignbr1' }, api(200, { data: [] }, calls));
  assert.match(result.content[0].text, /branch_not_allowed/);
  assert.equal(calls.length, 0);
});

test('the branch check fails closed when the branch list cannot be obtained', async () => {
  const calls = [];
  const failures = [
    async () => reply(500, {}),
    async () => {
      throw new Error('ECONNREFUSED');
    },
    async () => reply(200, { data: { branches: 'all' } }),
  ];
  for (const failing of failures) {
    const result = await callTool(fresh({ branchId: 'branch12345' }), 'erp_bookings_list', {}, async (url, init) => {
      if (String(url).endsWith('/mcp/context')) return failing(url, init);
      calls.push(url);
      return reply(200, { data: [] });
    });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /upstream_unavailable/);
  }
  assert.equal(calls.length, 0);
});

test('the branch list is cached between calls and refreshed after its time to live', async () => {
  let contextCalls = 0;
  let clockMs = 1_000_000;
  const config = fresh({ now: () => clockMs, contextTtlMs: 1000 });
  const fetchImpl = async (url) => {
    if (String(url).endsWith('/mcp/context')) {
      contextCalls += 1;
      return contextReply();
    }
    return reply(200, { data: [] });
  };
  await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.equal(contextCalls, 1);
  clockMs += 1500;
  await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.equal(contextCalls, 2);
});

// ---- feature flag and budget errors -----------------------------------------------------------

test('mcp_disabled and budget errors from the ERP are reported with clear codes', async () => {
  const off = await callTool(fresh(), 'erp_bookings_list', {}, async () => reply(403, { code: 'mcp_disabled', message: 'switched off' }));
  assert.match(off.content[0].text, /"code":"mcp_disabled"/);
  assert.match(off.content[0].text, /mcp\.enabled/);

  const budget = await callTool(fresh(), 'erp_bookings_list', {}, async (url) => {
    if (String(url).endsWith('/mcp/context')) return contextReply();
    return reply(429, { code: 'mcp_budget_exceeded' }, { 'retry-after': '3600' });
  });
  assert.match(budget.content[0].text, /"code":"budget_exceeded"/);
  assert.match(budget.content[0].text, /"retry_after":3600/);
});

// ---- circuit breaker (AT-13) ------------------------------------------------------------------

test('circuit breaker: repeated 5xx or limit denials pause calls, a success after the cooldown closes it', async () => {
  let clockMs = 5_000_000;
  const seen = [];
  let mode = 500;
  const fetchImpl = async (url) => {
    if (String(url).endsWith('/mcp/context')) return contextReply();
    seen.push(url);
    return mode === 200 ? reply(200, { data: [] }) : reply(mode, {});
  };
  const config = fresh({ now: () => clockMs, breakerThreshold: 3, breakerCooldownMs: 30_000 });

  for (let i = 0; i < 3; i++) {
    const r = await callTool(config, 'erp_bookings_list', {}, fetchImpl);
    assert.match(r.content[0].text, /upstream_unavailable/);
  }
  assert.equal(seen.length, 3);

  const paused = await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.match(paused.content[0].text, /"code":"circuit_open"/);
  assert.match(paused.content[0].text, /"retry_after":30/);
  assert.equal(seen.length, 3, 'no upstream call while the breaker is open');

  clockMs += 31_000;
  mode = 200;
  const probe = await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.equal(probe.isError, undefined);
  assert.equal(seen.length, 4);

  // 429 limit denials count too, and a failed probe after the cooldown re-opens the breaker at once.
  mode = 429;
  for (let i = 0; i < 3; i++) await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.match((await callTool(config, 'erp_bookings_list', {}, fetchImpl)).content[0].text, /circuit_open/);
  clockMs += 31_000;
  await callTool(config, 'erp_bookings_list', {}, fetchImpl);
  assert.match((await callTool(config, 'erp_bookings_list', {}, fetchImpl)).content[0].text, /circuit_open/);
});

test('answers that are not failures (404, 403) reset the failure count', async () => {
  let status = 500;
  const fetchImpl = async (url) => (String(url).endsWith('/mcp/context') ? contextReply() : reply(status, {}));
  const config = fresh({ breakerThreshold: 3 });
  for (const next of [500, 500, 404, 500, 500, 404, 500, 500]) {
    status = next;
    const r = await callTool(config, 'erp_bookings_list', {}, fetchImpl);
    assert.doesNotMatch(r.content[0].text, /circuit_open/);
  }
});

test('breaker and cooldown are configurable from the environment and validated', () => {
  const ok = loadConfig({ API_BASE_URL: 'https://x', MCP_TOKEN: 't', MCP_BREAKER_THRESHOLD: '2', MCP_BREAKER_COOLDOWN_SECONDS: '10' });
  assert.equal(ok.breakerThreshold, 2);
  assert.equal(ok.breakerCooldownMs, 10_000);
  assert.equal(loadConfig({ API_BASE_URL: 'https://x', MCP_TOKEN: 't' }).breakerThreshold, 5);
  assert.throws(() => loadConfig({ API_BASE_URL: 'https://x', MCP_TOKEN: 't', MCP_BREAKER_THRESHOLD: '0' }), /MCP_BREAKER_THRESHOLD/);
});

// ---- the gateway and the ERP allowlist must describe the same routes --------------------------

test('the tool list and the ERP allowlist (McpReadOnly::ALLOWED) are the same set of routes', () => {
  const php = readFileSync(new URL('../../backend/app/Http/Middleware/McpReadOnly.php', import.meta.url), 'utf8');
  const start = php.indexOf('public const ALLOWED');
  const block = php.slice(start, php.indexOf('];', start));
  const allowed = Object.fromEntries(
    [...block.matchAll(/'api\/v1\/([^']+)' => '([a-z_]+)'/g)].map(([, uri, tool]) => [tool, `/${uri.replace(/\{[a-z]+\}/, '{id}')}`]),
  );
  delete allowed.mcp_context;
  assert.deepEqual(allowed, Object.fromEntries(TOOLS.map((t) => [t.name, t.route])));
});
