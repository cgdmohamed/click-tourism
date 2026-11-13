// Gateway configuration comes only from the environment; nothing is stored in the repo.

const PUBLIC_ID = /^[A-Za-z0-9_-]{8,64}$/;

function positiveInt(raw, fallback, name) {
  if (raw === undefined || String(raw).trim() === '') return fallback;
  const value = Number(raw);
  if (!Number.isInteger(value) || value < 1 || value > 86400) throw new Error(`${name} must be a whole number from 1 to 86400.`);
  return value;
}

export function loadConfig(env = process.env) {
  const rawBase = (env.API_BASE_URL ?? '').trim();
  if (!rawBase) throw new Error('API_BASE_URL is required (e.g. https://erp.example.com).');

  let url;
  try {
    url = new URL(rawBase);
  } catch {
    throw new Error('API_BASE_URL is not a valid URL.');
  }
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('API_BASE_URL must be http(s).');

  const path = url.pathname.replace(/\/+$/, '');
  const baseUrl = `${url.origin}${path.endsWith('/api/v1') ? path : `${path}/api/v1`}`;

  const token = (env.MCP_TOKEN ?? '').trim();
  if (!token) throw new Error('MCP_TOKEN is required (create one in the ERP: Profile, MCP tokens).');

  const branchId = (env.MCP_BRANCH_ID ?? '').trim() || null;
  if (branchId && !PUBLIC_ID.test(branchId)) throw new Error('MCP_BRANCH_ID is not a valid branch public id.');

  // Defense in depth only: the ERP masks contacts itself via the mcp.mask_contacts setting.
  const maskContacts = !['0', 'false', 'no', 'off'].includes((env.MCP_MASK_CONTACTS ?? 'true').toLowerCase());

  // Circuit breaker: after this many consecutive upstream failures (5xx, network, 429) calls
  // pause for the cooldown. Both are installation knobs for the person running the gateway.
  const breakerThreshold = positiveInt(env.MCP_BREAKER_THRESHOLD, 5, 'MCP_BREAKER_THRESHOLD');
  const breakerCooldownMs = positiveInt(env.MCP_BREAKER_COOLDOWN_SECONDS, 60, 'MCP_BREAKER_COOLDOWN_SECONDS') * 1000;

  return { baseUrl, token, branchId, maskContacts, timeoutMs: 15000, breakerThreshold, breakerCooldownMs, contextTtlMs: 5 * 60 * 1000 };
}
