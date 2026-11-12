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

/** Maps validated arguments to a query string; branch_id travels as a header, never in the URL. */
export function buildQuery(name, args) {
  const params = new URLSearchParams();
  const tool = TOOLS_BY_NAME[name];
  for (const key of Object.keys(tool.inputSchema.properties)) {
    if (key === 'branch_id' || args[key] === undefined) continue;
    const value = typeof args[key] === 'boolean' ? (args[key] ? '1' : '0') : String(args[key]);
    params.set(key, value);
  }
  if (tool.inputSchema.properties.per_page && args.per_page === undefined) {
    params.set('per_page', '25');
  }
  return params.toString();
}

function fail(code, message, extra) {
  return { isError: true, content: [{ type: 'text', text: errorEnvelope(code, message, extra) }] };
}

/** Stable, non-leaking error codes (blueprint section 4). The upstream body is never forwarded. */
export function mapHttpError(status, body, headers) {
  if (status === 401) return fail('unauthenticated', 'The MCP token is missing, expired or revoked.');
  if (status === 403) {
    if (body?.code === 'account_disabled') return fail('account_disabled', 'This account is disabled.');
    if (body?.code === 'mcp_scope_denied') return fail('mcp_scope_denied', 'This token may only use the read-only MCP tools.');
    return fail('forbidden', 'Not available to your role or branch.');
  }
  if (status === 404) return fail('not_found', 'Record not found.');
  if (status === 413) return fail('payload_too_large', 'Request too large.');
  if (status === 422) return fail('validation_failed', 'The request was rejected as invalid.');
  if (status === 429) {
    const retry = Number.parseInt(headers?.get?.('retry-after') ?? '', 10);
    return fail('rate_limited', 'Too many requests; slow down.', Number.isFinite(retry) ? { retry_after: retry } : {});
  }
  return fail('upstream_unavailable', 'The ERP is temporarily unavailable.');
}

/**
 * Runs one tool call. `config` = { baseUrl, token, branchId, maskContacts, timeoutMs }.
 * `fetchImpl` is injectable for tests.
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

  const query = buildQuery(name, args);
  const url = `${config.baseUrl}${tool.route}${query ? `?${query}` : ''}`;
  const requestId = globalThis.crypto.randomUUID();
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.token}`,
    'X-Request-Id': requestId,
    'X-Mcp-Tool': name,
    ...(branchId ? { 'X-Branch-Id': branchId } : {}),
  };

  let response;
  try {
    response = await fetchImpl(url, {
      method: 'GET',
      headers,
      redirect: 'error',
      signal: AbortSignal.timeout(config.timeoutMs ?? 15000),
    });
  } catch {
    return fail('upstream_unavailable', 'The ERP is temporarily unavailable.');
  }

  let body = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }

  if (!response.ok) return mapHttpError(response.status, body, response.headers);
  if (body === null || typeof body !== 'object') return fail('upstream_unavailable', 'Unexpected response from the ERP.');

  const text = buildEnvelope({
    tool: name,
    route: tool.route,
    branchId,
    requestId,
    body,
    maskContacts: config.maskContacts !== false,
  });
  return { content: [{ type: 'text', text }] };
}
