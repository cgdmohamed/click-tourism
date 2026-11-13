import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { TOOLS, TOOLS_BY_NAME } from './schemas.js';
import { buildEnvelope, errorEnvelope } from './sanitize.js';

const ajv = new Ajv2020({ allErrors: true, strict: true, useDefaults: false, coerceTypes: false });
addFormats(ajv);
const validators = Object.fromEntries(TOOLS.map((tool) => [tool.name, ajv.compile(tool.inputSchema)]));

/** Validates tool arguments against the tool's JSON Schema. Returns an error string or null. */
export function validateArguments(name, args) {
  const validate = validators[name];
  if (!validate) return `Unknown tool: ${name}`;
  if (validate(args ?? {})) return null;
  return validate.errors.map((e) => `${e.instancePath || '(root)'} ${e.message}`).join('; ');
}

/** Fills `{id}` in a route template from the validated `id` argument (already matches ^[A-Za-z0-9_-]+$). */
export function buildPath(name, args) {
  return TOOLS_BY_NAME[name].route.replace('{id}', encodeURIComponent(args.id ?? ''));
}

/** Maps validated arguments to a query string; branch_id travels as a header, never in the URL. */
export function buildQuery(name, args) {
  const params = new URLSearchParams();
  const tool = TOOLS_BY_NAME[name];
  for (const key of Object.keys(tool.inputSchema.properties)) {
    if (key === 'branch_id' || key === 'id' || args[key] === undefined) continue;
    const value = typeof args[key] === 'boolean' ? (args[key] ? '1' : '0') : String(args[key]);
    params.set(key, value);
  }
  const perPage = tool.inputSchema.properties.per_page;
  if (perPage?.default !== undefined && args.per_page === undefined) {
    params.set('per_page', String(perPage.default));
  }
  return params.toString();
}

function fail(code, message, extra) {
  return { isError: true, content: [{ type: 'text', text: errorEnvelope(code, message, extra) }] };
}

const retryAfter = (headers) => {
  const retry = Number.parseInt(headers?.get?.('retry-after') ?? '', 10);
  return Number.isFinite(retry) ? { retry_after: retry } : {};
};

/** Stable, non-leaking error codes (blueprint section 4). The upstream body is never forwarded. */
export function mapHttpError(status, body, headers) {
  if (status === 401) return fail('unauthenticated', 'The MCP token is missing, expired or revoked.');
  if (status === 403) {
    if (body?.code === 'account_disabled') return fail('account_disabled', 'This account is disabled.');
    if (body?.code === 'mcp_disabled') return fail('mcp_disabled', 'MCP access is switched off in this ERP installation (setting mcp.enabled).');
    if (body?.code === 'mcp_scope_denied') return fail('mcp_scope_denied', 'This token may only use the read-only MCP tools.');
    return fail('forbidden', 'Not available to your role or branch.');
  }
  if (status === 404) return fail('not_found', 'Record not found.');
  if (status === 413) return fail('payload_too_large', 'Request too large.');
  if (status === 422) return fail('validation_failed', 'The request was rejected as invalid.');
  if (status === 429) {
    if (body?.code === 'mcp_budget_exceeded') {
      return fail('budget_exceeded', 'The daily row budget of this token is used up. Do not retry before tomorrow.', retryAfter(headers));
    }
    return fail('rate_limited', 'Too many requests; slow down.', retryAfter(headers));
  }
  return fail('upstream_unavailable', 'The ERP is temporarily unavailable.');
}

// ---- per-config state: circuit breaker and the branch list of the token's user ---------------

const states = new WeakMap();

function stateFor(config) {
  let state = states.get(config);
  if (!state) {
    state = { failures: 0, openUntil: 0, context: null };
    states.set(config, state);
  }
  return state;
}

const clock = (config) => (config.now ? config.now() : Date.now());

/** Failures that count towards the breaker: network errors, 5xx and 429 (limit denials). */
const isBreakerFailure = (status) => status === null || status >= 500 || status === 429;

function recordOutcome(config, state, status) {
  if (isBreakerFailure(status)) {
    state.failures += 1;
    if (state.failures >= (config.breakerThreshold ?? 5)) {
      state.openUntil = clock(config) + (config.breakerCooldownMs ?? 60000);
    }
  } else {
    state.failures = 0;
  }
}

/** One upstream GET. Returns { status (null on network error), ok, body, headers }. */
async function upstream(config, fetchImpl, url, headers) {
  let response;
  try {
    response = await fetchImpl(url, {
      method: 'GET',
      headers,
      redirect: 'error',
      signal: AbortSignal.timeout(config.timeoutMs ?? 15000),
    });
  } catch {
    return { status: null, ok: false, body: null, headers: undefined };
  }
  let body = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }
  return { status: response.status, ok: response.ok, body, headers: response.headers };
}

/**
 * Second branch filter (blueprint section 5): the branches of the token's user come from
 * GET /mcp/context, cached for contextTtlMs. A pinned or requested branch outside that list is
 * refused, and so is any call for which the list cannot be obtained (fail closed).
 * Returns { error } or { branches }.
 */
async function loadBranches(config, state, fetchImpl, headers) {
  const now = clock(config);
  if (state.context && state.context.expires > now) return { branches: state.context.branches };

  const result = await upstream(config, fetchImpl, `${config.baseUrl}/mcp/context`, { ...headers, 'X-Mcp-Tool': 'mcp_context' });
  recordOutcome(config, state, result.status);
  if (!result.ok) return { error: mapHttpError(result.status ?? 503, result.body, result.headers) };

  const branches = result.body?.data?.branches;
  if (!Array.isArray(branches) || !branches.every((id) => typeof id === 'string')) {
    return { error: fail('upstream_unavailable', 'Unexpected response from the ERP.') };
  }
  state.context = { branches: new Set(branches), expires: now + (config.contextTtlMs ?? 300000) };
  return { branches: state.context.branches };
}

/**
 * Runs one tool call. `config` = { baseUrl, token, branchId, maskContacts, timeoutMs, breakerThreshold,
 * breakerCooldownMs, contextTtlMs, now }. `fetchImpl` is injectable for tests.
 */
export async function callTool(config, name, rawArgs, fetchImpl = fetch) {
  const tool = TOOLS_BY_NAME[name];
  if (!tool) return fail('validation_failed', `Unknown tool: ${name}`);

  const args = rawArgs ?? {};
  const problem = validateArguments(name, args);
  if (problem) return fail('validation_failed', problem);

  // A branch pinned in the gateway configuration cannot be changed by tool arguments.
  if (config.branchId && args.branch_id && args.branch_id !== config.branchId) {
    return fail('validation_failed', 'branch_id differs from the branch configured for this gateway.');
  }
  const branchId = args.branch_id ?? config.branchId ?? null;

  // Circuit breaker: paused after repeated upstream failures or limit denials.
  const state = stateFor(config);
  const now = clock(config);
  if (state.openUntil > now) {
    return fail('circuit_open', 'Calls are paused after repeated ERP failures or limit denials. Try again later.', {
      retry_after: Math.ceil((state.openUntil - now) / 1000),
    });
  }

  const requestId = globalThis.crypto.randomUUID();
  const baseHeaders = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.token}`,
    'X-Request-Id': requestId,
    ...(branchId ? { 'X-Branch-Id': branchId } : {}),
  };

  const known = await loadBranches(config, state, fetchImpl, baseHeaders);
  if (known.error) return known.error;
  if (branchId && !known.branches.has(branchId)) {
    return fail('branch_not_allowed', 'The branch is not one of the branches assigned to this token\'s user.');
  }

  const path = buildPath(name, args);
  const query = buildQuery(name, args);
  const result = await upstream(config, fetchImpl, `${config.baseUrl}${path}${query ? `?${query}` : ''}`, {
    ...baseHeaders,
    'X-Mcp-Tool': name,
  });
  recordOutcome(config, state, result.status);

  if (!result.ok) return mapHttpError(result.status ?? 503, result.body, result.headers);
  const body = result.body;
  if (body === null || typeof body !== 'object') return fail('upstream_unavailable', 'Unexpected response from the ERP.');

  const text = buildEnvelope({
    tool: name,
    route: path,
    branchId,
    requestId,
    body,
    maskContacts: config.maskContacts !== false,
  });
  return { content: [{ type: 'text', text }] };
}
